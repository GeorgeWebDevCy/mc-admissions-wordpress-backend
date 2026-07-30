<?php
/**
 * Plugin Name: MC Admissions WordPress Backend
 * Plugin URI: https://www.mesoyios.ac.cy/
 * Description: WordPress REST backend for the MC Admissions desktop app.
 * Version: 0.2.50
 * Requires at least: 6.2
 * Author: Mesoyios College
 * Author URI: https://www.mesoyios.ac.cy/
 * License: GPL-2.0-or-later
 * Text Domain: mc-admissions-wordpress-backend
 */

if (!defined('ABSPATH')) {
	exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if (!class_exists('MC_Admissions_WordPress_Backend')) {
	final class MC_Admissions_WordPress_Backend {
		const API_NAMESPACE = 'mc-admissions/v1';
		const DEFAULT_SOURCE = 'mc-admissions-wordpress';
		const INITIAL_APPLICATION_STATUS = 'Application in progress';
		const STALE_APPLICATION_ERROR = 'This application changed since you opened it. Refresh and try again.';
		const AUTH_EPOCH_META_KEY = 'mc_admissions_auth_epoch';
		const AUTH_EPOCH_CLAIM = 'mcAdmissionsAuthEpoch';
		const PASSWORD_ATTEMPT_LIMIT = 5;
		const PASSWORD_ATTEMPT_WINDOW_SECONDS = 900;
		const NOTIFICATION_EVENT_PAGE_SIZE = 50;
		const NOTIFICATION_DOCUMENT_ACTIVITY_KIND = 'agent-document-upload';
		const PRESIDENT_ACTIVITY_ALERT_EMAIL = 'president@mesoyios.ac.cy';
		const PRESIDENT_ACTIVITY_ALERT_NAME = 'Theodoros';
		const DESKTOP_RELEASE_REPOSITORY = 'GeorgeWebDevCy/mc-admissions-app';
		const RELEASE_NOTIFICATION_SECRET_SETTING = 'release_notification_secret';
		const RELEASE_NOTIFICATION_STATE_PREFIX = 'release_notification_delivery_';
		const RELEASE_NOTIFICATION_LOCK_PREFIX = 'mc_admissions_release_';

		/** @var string */
		private $applications_table = 'mc_admission_applications';

		/** @var string */
		private $documents_table = 'mc_admission_documents';

		/** @var string */
		private $activities_table = 'mc_admission_activities';

		/** @var string */
		private $communications_table = 'mc_admission_communications';

		/** @var string */
		private $letter_drafts_table = 'mc_admission_letter_drafts';

		/** @var string */
		private $settings_table = 'mc_admission_settings';

		/** @var string */
		private $payments_table = 'mc_admission_payments';

		/** @var string */
		private $commission_records_table = 'mc_commission_records';

		/** @var string */
		private $refund_records_table = 'mc_refund_records';

		/** @var string */
		private $migration_cases_table = 'mc_admission_migration_cases';

		/** @var string */
		private $immigration_cases_table = 'mc_admission_immigration_cases';

		/** @var string */
		private $agency_profiles_table = 'mc_agency_profiles';

		/** @var WP_Error|null */
		private $jwt_auth_epoch_error = null;

		/** @var bool[] */
		private $password_epoch_preadvanced_user_ids = array();

		/** @var string[] */
		private $document_requirements = array(
			'passport' => 'Copy of passport',
			'secondaryMarksheet' => 'Copy of Secondary School (10th grade) marksheet',
			'higherSecondaryMarksheet' => 'Copy of Higher Secondary School (12th grade) marksheet',
			'englishCertificate' => 'English proficiency certificate',
			'studentSignature' => 'Student signature',
			'consultantSignature' => 'Agent / consultant signature',
			'agencyAgreement' => 'Agency agreement',
			'authorizationCertificate' => 'Authorization certificate',
			'bachelorDiploma' => 'Bachelor diploma',
			'bachelorTranscript' => 'Bachelor transcripts',
			'bankTransactionConfirmation' => 'Bank transaction confirmation',
			'migrationSupportingDocuments' => 'Migration supporting documents',
			'entryPermitPaymentReceipt' => 'Entry permit payment receipt',
			'entryPermitRecord' => 'Issued entry permit record',
			'courierReceipt' => 'Courier or dispatch receipt',
			'afterArrivalPaymentReceipt' => 'After-arrival payment receipt',
			'enrollmentAgreement' => 'Enrollment agreement',
			'bankStatement' => 'Bank statement',
			'rentalAgreement' => 'Rental agreement',
			'medicalCertificate' => 'Medical certificate',
			'xRayRecord' => 'X-ray record',
			'immigrationAppointmentRecord' => 'Immigration appointment record',
			'immigrationPaymentReceipt' => 'Immigration payment receipt',
			'pinkCardRecord' => 'Pink card record',
			'insuranceCopy' => 'Copy of Insurance',
		);

		/** @var string[] */
		private $programme_labels = array(
			'hotel-casino-resort-management' => "Bachelor's degree in Hotel, Casino & Resort Management",
			'business-administration' => "Bachelor's degree in Business Administration",
			'english-foundation' => 'English Foundation Year',
		);

		/** @var string[] */
		private $pipeline_stages = array(
			'profile-preparation',
			'review-pending',
			'offer-issued',
			'prepayment-pending',
			'acceptance-issued',
			'migration-documents',
			'entry-permit-processing',
			'arrival-immigration',
			'enrollment-complete',
			'rejected',
			'trashed',
			'Application in progress',
			'Under review',
			'Offer letter issued',
			'Payment pending',
			'Acceptance confirmed',
			'Entry permit processing',
			'Ready to enroll',
		);

		/** @var string[] */
		private $reviewer_decisions = array(
			'pending',
			'academically-cleared',
			'conditional-offer',
			'hold',
			'rejected',
		);

		/** @var string[] */
		private $payment_statuses = array(
			'awaiting-invoice',
			'awaiting-payment',
			'receipt-received',
			'cleared',
		);

		/** @var string[] */
		private $permit_statuses = array(
			'not-started',
			'preparing-pack',
			'submitted',
			'approved',
			'declined',
		);

		/** @var string[] */
		private $arrival_statuses = array(
			'planning',
			'travel-booked',
			'arrived',
		);

		/** @var string[] */
		private $enrollment_statuses = array(
			'pending',
			'scheduled',
			'enrolled',
		);

		/** @var string[] */
		private $commission_statuses = array(
			'not-applicable',
			'pending-approval',
			'ready-to-invoice',
			'invoiced',
			'paid',
			'withheld',
		);

		/** @var string[] */
		private $refund_statuses = array(
			'none',
			'requested',
			'under-review',
			'approved',
			'paid',
			'declined',
		);

		public function boot() {
			$this->ensure_roles();
			$this->ensure_application_test_data_schema();
			$this->ensure_immigration_insurance_columns();
			$this->ensure_offer_detail_columns();
			$this->ensure_case_detail_columns();
			$this->ensure_document_assessment_columns();
			$this->ensure_resource_indexes();
			$this->ensure_notification_activity_schema();
			$this->boot_update_checker();
			// Run before Plugin Update Checker's source-selection callback. PUC also
			// attempts to rename the extracted directory and returns puc-rename-failed
			// before later callbacks can repair a mismatched package path.
			add_filter('upgrader_source_selection', array($this, 'normalize_update_package_paths'), 5, 4);
			add_action('admin_menu', array($this, 'register_admin_menu'));
			add_action('rest_api_init', array($this, 'register_rest_routes'));
			add_action('wp_set_password', array($this, 'advance_auth_epoch_after_password_change'), 10, 3);
			add_filter('jwt_auth_token_before_sign', array($this, 'add_jwt_auth_epoch_claim'), 10, 2);
			add_filter('determine_current_user', array($this, 'enforce_jwt_auth_epoch'), 100, 1);
			add_filter('rest_authentication_errors', array($this, 'surface_jwt_auth_epoch_error'), 20, 1);
			add_filter('rest_pre_dispatch', array($this, 'disable_mc_admissions_rest_cache'), 10, 3);
			add_filter('rest_post_dispatch', array($this, 'add_mc_admissions_rest_no_cache_headers'), 10, 3);
			add_filter('rest_pre_serve_request', array($this, 'send_rest_cors_headers'), 10, 4);
		}

		private function ensure_notification_activity_schema() {
			global $wpdb;

			if ('1' === get_option('mc_admissions_notification_activity_schema_version')) {
				return;
			}

			if ($this->activities_table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->activities_table))) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$actor_role_column = $wpdb->get_var("SHOW COLUMNS FROM {$this->activities_table} LIKE 'actorRole'");
			if (!$actor_role_column) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query("ALTER TABLE {$this->activities_table} ADD COLUMN actorRole VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER actorName");
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$event_index = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$this->activities_table} WHERE Key_name = %s", 'mc_admission_activities_actorRole_createdAt_idx'));
			if (!$event_index) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query("ALTER TABLE {$this->activities_table} ADD INDEX mc_admission_activities_actorRole_createdAt_idx (actorRole, createdAt)");
			}

			update_option('mc_admissions_notification_activity_schema_version', '1', false);
		}

		private function ensure_application_test_data_schema() {
			global $wpdb;

			if ('1' === get_option('mc_admissions_application_test_data_schema_version')) {
				return;
			}

			// The application table may predate the test-data classification field.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ($this->applications_table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->applications_table))) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$present = $wpdb->get_var("SHOW COLUMNS FROM {$this->applications_table} LIKE 'isTestData'");
			if (!$present) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$added = $wpdb->query("ALTER TABLE {$this->applications_table} ADD COLUMN isTestData BOOLEAN NOT NULL DEFAULT 0 AFTER gdprAcknowledged");
				if (false === $added) {
					return;
				}
			}

			// Confirm the column exists before recording the migration as complete.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$present = $wpdb->get_var("SHOW COLUMNS FROM {$this->applications_table} LIKE 'isTestData'");
			if ($present) {
				update_option('mc_admissions_application_test_data_schema_version', '1', false);
			}
		}

		private function ensure_resource_indexes() {
			global $wpdb;

			if ('1' === get_option('mc_admissions_resource_index_version')) {
				return;
			}

			// These indexes support the two hottest reads: the full board ordered by
			// updatedAt and agent-scoped boards filtered by wordpressUserId.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ($this->applications_table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->applications_table))) {
				return;
			}

			$indexes = array(
				array($this->applications_table, 'mc_admission_applications_updatedAt_idx', 'updatedAt'),
				array($this->applications_table, 'mc_admission_applications_user_updatedAt_idx', 'wordpressUserId, updatedAt'),
				array($this->documents_table, 'mc_admission_documents_application_ready_idx', 'applicationId, isReady'),
			);

			foreach ($indexes as $index) {
				list($table, $name, $columns) = $index;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$present = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $name));
				if (!$present) {
					// Names and columns are internal constants, never request input.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query("ALTER TABLE {$table} ADD INDEX {$name} ({$columns})");
				}
			}

			update_option('mc_admissions_resource_index_version', '1', false);
		}

		private function ensure_immigration_insurance_columns() {
			global $wpdb;

			if ('0.2.14' === get_option('mc_admissions_schema_version')) {
				return;
			}

			// The class boots once before the activation hook on a brand-new install.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ($this->immigration_cases_table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->immigration_cases_table))) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$policy_column = $wpdb->get_var("SHOW COLUMNS FROM {$this->immigration_cases_table} LIKE 'insurancePolicyNumber'");
			if (!$policy_column) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query("ALTER TABLE {$this->immigration_cases_table} ADD COLUMN insurancePolicyNumber VARCHAR(191) NULL AFTER paymentReference");
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$expiration_column = $wpdb->get_var("SHOW COLUMNS FROM {$this->immigration_cases_table} LIKE 'insuranceExpirationDate'");
			if (!$expiration_column) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query("ALTER TABLE {$this->immigration_cases_table} ADD COLUMN insuranceExpirationDate VARCHAR(191) NULL AFTER insurancePolicyNumber");
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$policy_column = $wpdb->get_var("SHOW COLUMNS FROM {$this->immigration_cases_table} LIKE 'insurancePolicyNumber'");
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$expiration_column = $wpdb->get_var("SHOW COLUMNS FROM {$this->immigration_cases_table} LIKE 'insuranceExpirationDate'");
			if ($policy_column && $expiration_column) {
				update_option('mc_admissions_schema_version', '0.2.14');
			}
		}

		private function ensure_offer_detail_columns() {
			global $wpdb;

			if ('0.2.38' === get_option('mc_admissions_offer_detail_schema_version')) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ($this->applications_table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->applications_table))) {
				return;
			}

			$columns = array(
				'classesStartDate' => "VARCHAR(191) NULL AFTER offerConditionNote",
				'tuitionFeeFirstYear' => "VARCHAR(191) NULL AFTER classesStartDate",
				'tuitionFeeFollowingYears' => "VARCHAR(191) NULL AFTER tuitionFeeFirstYear",
				'termBalanceApplies' => "BOOLEAN NOT NULL DEFAULT 0 AFTER tuitionFeeFollowingYears",
			);

			foreach ($columns as $column => $definition) {
				// Column names and definitions are internal constants.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$present = $wpdb->get_var("SHOW COLUMNS FROM {$this->applications_table} LIKE '{$column}'");
				if (!$present) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query("ALTER TABLE {$this->applications_table} ADD COLUMN {$column} {$definition}");
				}
			}

			update_option('mc_admissions_offer_detail_schema_version', '0.2.38', false);
		}

		private function ensure_case_detail_columns() {
			global $wpdb;

			if ('0.2.45' === get_option('mc_admissions_case_detail_schema_version')) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ($this->applications_table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->applications_table))) {
				return;
			}

			// Column names and definitions are internal constants.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$present = $wpdb->get_var("SHOW COLUMNS FROM {$this->applications_table} LIKE 'lateArrivalReason'");
			if (!$present) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$added = $wpdb->query("ALTER TABLE {$this->applications_table} ADD COLUMN lateArrivalReason TEXT NULL AFTER enrollmentNote");
				if (false === $added) {
					return;
				}
			}

			update_option('mc_admissions_case_detail_schema_version', '0.2.45', false);
		}

		private function ensure_document_assessment_columns() {
			global $wpdb;

			if ('1' === get_option('mc_admissions_document_assessment_schema_version')) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ($this->documents_table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->documents_table))) {
				return;
			}

			$columns = array(
				'assessmentStatus' => "VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER isReady",
				'assessmentRemark' => 'TEXT NULL AFTER assessmentStatus',
				'assessedAt' => 'DATETIME(3) NULL AFTER assessmentRemark',
				'assessedByName' => 'VARCHAR(191) NULL AFTER assessedAt',
			);

			foreach ($columns as $column => $definition) {
				// Column names and definitions are internal constants.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$present = $wpdb->get_var("SHOW COLUMNS FROM {$this->documents_table} LIKE '{$column}'");
				if (!$present) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$added = $wpdb->query("ALTER TABLE {$this->documents_table} ADD COLUMN {$column} {$definition}");
					if (false === $added) {
						return;
					}
				}
			}

			update_option('mc_admissions_document_assessment_schema_version', '1', false);
		}

		public function activate() {
			$this->ensure_roles();
			global $wpdb;

			$charset = $wpdb->get_charset_collate();

			$statements = array(
				"
				CREATE TABLE IF NOT EXISTS {$this->applications_table} (
					id VARCHAR(191) NOT NULL,
					referenceCode VARCHAR(191) NOT NULL,
					wordpressUserId INT NULL,
					wordpressUsername VARCHAR(191) NULL,
					wordpressEmail VARCHAR(191) NULL,
					fullName VARCHAR(191) NOT NULL,
					passportNumber VARCHAR(191) NOT NULL,
					email VARCHAR(191) NOT NULL,
					phone VARCHAR(191) NOT NULL DEFAULT '',
					birthday VARCHAR(191) NOT NULL,
					address TEXT NOT NULL,
					city VARCHAR(191) NOT NULL,
					postalCode VARCHAR(191) NOT NULL,
					country VARCHAR(191) NOT NULL,
					gender VARCHAR(191) NOT NULL,
					semester VARCHAR(191) NOT NULL,
					year VARCHAR(191) NOT NULL,
					applicationRoute VARCHAR(191) NOT NULL DEFAULT 'standard',
					programmeCode VARCHAR(191) NOT NULL,
					programmeLabel VARCHAR(191) NOT NULL,
					agencyName VARCHAR(191) NOT NULL,
					consultantName VARCHAR(191) NOT NULL,
					consultantEmail VARCHAR(191) NULL,
					consultantPhone VARCHAR(191) NULL,
					submissionDate VARCHAR(191) NULL,
					tuitionAcknowledged BOOLEAN NOT NULL,
					offerTermsAcknowledged BOOLEAN NOT NULL,
					gdprAcknowledged BOOLEAN NOT NULL,
					isTestData BOOLEAN NOT NULL DEFAULT 0,
					status VARCHAR(191) NOT NULL DEFAULT 'Application in progress',
					workflowNote TEXT NULL,
					lastUpdatedByName VARCHAR(191) NULL,
					reviewSummary TEXT NULL,
					reviewerDecision VARCHAR(191) NOT NULL DEFAULT 'pending',
					decisionDueDate VARCHAR(191) NULL,
					offerIssuedDate VARCHAR(191) NULL,
					offerExpiryDate VARCHAR(191) NULL,
					offerConditionNote TEXT NULL,
					classesStartDate VARCHAR(191) NULL,
					tuitionFeeFirstYear VARCHAR(191) NULL,
					tuitionFeeFollowingYears VARCHAR(191) NULL,
					termBalanceApplies BOOLEAN NOT NULL DEFAULT 0,
					paymentStatus VARCHAR(191) NOT NULL DEFAULT 'awaiting-invoice',
					paymentAmount VARCHAR(191) NULL,
					paymentCurrency VARCHAR(191) NOT NULL DEFAULT 'EUR',
					paymentReference VARCHAR(191) NULL,
					paymentConfirmedDate VARCHAR(191) NULL,
					financeNote TEXT NULL,
					permitStatus VARCHAR(191) NOT NULL DEFAULT 'not-started',
					permitReference VARCHAR(191) NULL,
					permitSubmittedDate VARCHAR(191) NULL,
					permitDecisionDate VARCHAR(191) NULL,
					permitNote TEXT NULL,
					arrivalStatus VARCHAR(191) NOT NULL DEFAULT 'planning',
					travelDate VARCHAR(191) NULL,
					accommodationStatus VARCHAR(191) NULL,
					enrollmentStatus VARCHAR(191) NOT NULL DEFAULT 'pending',
					orientationDate VARCHAR(191) NULL,
					enrollmentNote TEXT NULL,
					lateArrivalReason TEXT NULL,
					source VARCHAR(191) NOT NULL DEFAULT 'mc-admissions-wordpress',
					createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					updatedAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (id),
					UNIQUE KEY mc_admission_applications_referenceCode_key (referenceCode),
					KEY mc_admission_applications_updatedAt_idx (updatedAt),
					KEY mc_admission_applications_user_updatedAt_idx (wordpressUserId, updatedAt)
				) {$charset}
				",
				"
				CREATE TABLE IF NOT EXISTS {$this->documents_table} (
					id VARCHAR(191) NOT NULL,
					applicationId VARCHAR(191) NOT NULL,
					type VARCHAR(191) NOT NULL,
					label VARCHAR(191) NOT NULL,
					isReady BOOLEAN NOT NULL DEFAULT FALSE,
					assessmentStatus VARCHAR(32) NOT NULL DEFAULT 'pending',
					assessmentRemark TEXT NULL,
					assessedAt DATETIME(3) NULL,
					assessedByName VARCHAR(191) NULL,
					uploadedUrl TEXT NULL,
					storedFilename VARCHAR(255) NULL,
					storageProvider VARCHAR(191) NULL DEFAULT 'microsoft-365',
					storageDriveId VARCHAR(191) NULL,
					storageItemId VARCHAR(191) NULL,
					storagePath TEXT NULL,
					storageWebUrl TEXT NULL,
					originalName VARCHAR(255) NULL,
					mimeType VARCHAR(191) NULL,
					fileSizeBytes INT NULL,
					uploadedAt VARCHAR(191) NULL,
					uploadedByName VARCHAR(191) NULL,
					createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					updatedAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (id),
					UNIQUE KEY mc_admission_documents_applicationId_type_key (applicationId, type),
					KEY mc_admission_documents_applicationId_idx (applicationId),
					KEY mc_admission_documents_application_ready_idx (applicationId, isReady)
				) {$charset}
				",
				"
				CREATE TABLE IF NOT EXISTS {$this->activities_table} (
					id VARCHAR(191) NOT NULL,
					applicationId VARCHAR(191) NOT NULL,
					kind VARCHAR(191) NOT NULL,
					title VARCHAR(191) NOT NULL,
					detail TEXT NULL,
					actorName VARCHAR(191) NOT NULL,
					actorRole VARCHAR(32) NOT NULL DEFAULT 'unknown',
					createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (id),
					KEY mc_admission_activities_applicationId_createdAt_idx (applicationId, createdAt),
					KEY mc_admission_activities_actorRole_createdAt_idx (actorRole, createdAt)
				) {$charset}
				",
				"
				CREATE TABLE IF NOT EXISTS {$this->settings_table} (
					settingKey VARCHAR(191) NOT NULL,
					settingValue LONGTEXT NULL,
					updatedAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (settingKey)
				) {$charset}
				",
			"
				CREATE TABLE IF NOT EXISTS {$this->payments_table} (
					id VARCHAR(191) NOT NULL,
					applicationId VARCHAR(191) NOT NULL,
					amount VARCHAR(191) NOT NULL,
					currency VARCHAR(191) NOT NULL DEFAULT 'EUR',
					reference VARCHAR(191) NULL,
					swiftReference VARCHAR(191) NULL,
					confirmedDate VARCHAR(191) NULL,
					recordedByName VARCHAR(191) NOT NULL,
					note TEXT NULL,
					createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (id),
					KEY mc_admission_payments_applicationId_idx (applicationId, createdAt)
				) {$charset}
				",
			"
				CREATE TABLE IF NOT EXISTS {$this->migration_cases_table} (
					id VARCHAR(191) NOT NULL,
					applicationId VARCHAR(191) NOT NULL,
					packPreparedDate VARCHAR(191) NULL,
					packSubmittedDate VARCHAR(191) NULL,
					paymentReference VARCHAR(191) NULL,
					paymentDate VARCHAR(191) NULL,
					decisionDate VARCHAR(191) NULL,
					permitReference VARCHAR(191) NULL,
					note TEXT NULL,
					recordedByName VARCHAR(191) NOT NULL,
					createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					updatedAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (id),
					UNIQUE KEY mc_migration_cases_applicationId_key (applicationId)
				) {$charset}
				",
			"
				CREATE TABLE IF NOT EXISTS {$this->immigration_cases_table} (
					id VARCHAR(191) NOT NULL,
					applicationId VARCHAR(191) NOT NULL,
					arrivalDate VARCHAR(191) NULL,
					medicalCertDate VARCHAR(191) NULL,
					xRayDate VARCHAR(191) NULL,
					appointmentDate VARCHAR(191) NULL,
					paymentReference VARCHAR(191) NULL,
					insurancePolicyNumber VARCHAR(191) NULL,
					insuranceExpirationDate VARCHAR(191) NULL,
					pinkCardDate VARCHAR(191) NULL,
					enrollmentAgreementDate VARCHAR(191) NULL,
					note TEXT NULL,
					recordedByName VARCHAR(191) NOT NULL,
					createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					updatedAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (id),
					UNIQUE KEY mc_immigration_cases_applicationId_key (applicationId)
				) {$charset}
				",
			);

			foreach ($statements as $statement) {
				$wpdb->query($statement); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		private function admissions_role_definitions() {
			return array(
				'mc_agent' => __('MC Agent', 'mc-admissions-wordpress-backend'),
				'admissions-officer' => __('Admissions Officer', 'mc-admissions-wordpress-backend'),
				'finance-officer' => __('Finance Officer', 'mc-admissions-wordpress-backend'),
				'migration-officer' => __('Migration Officer', 'mc-admissions-wordpress-backend'),
				'immigration-officer' => __('Immigration Officer', 'mc-admissions-wordpress-backend'),
				'registrar' => __('Registrar', 'mc-admissions-wordpress-backend'),
			);
		}

		private function get_role_statuses() {
			$statuses = array();

			foreach ($this->admissions_role_definitions() as $slug => $label) {
				$role = get_role($slug);
				$statuses[$slug] = array(
					'slug' => $slug,
					'label' => $role ? $role->name : $label,
					'present' => (bool) ($role && $role->has_cap('read')),
				);
			}

			return $statuses;
		}

		public function ensure_roles() {
			foreach ($this->admissions_role_definitions() as $slug => $label) {
				$role = get_role($slug);

				if (!$role) {
					$role = add_role(
						$slug,
						$label,
						array(
							'read' => true,
						)
					);
				}

				if ($role && !$role->has_cap('read')) {
					$role->add_cap('read');
				}
			}
		}

		private function boot_update_checker() {
			if (!class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
				return;
			}

			$token = $this->get_setting('github_token');

			if (empty($token)) {
				return;
			}

			$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				'https://github.com/GeorgeWebDevCy/mc-admissions-wordpress-backend/',
				__FILE__,
				'mc-admissions-wordpress-backend'
			);

			$checker->setAuthentication($token);
			$checker->getVcsApi()->enableReleaseAssets(
				'/^mc-admissions-wordpress-backend\.zip$/',
				\YahnisElsts\PluginUpdateChecker\v5p6\Vcs\Api::REQUIRE_RELEASE_ASSETS
			);
		}

		public function normalize_update_package_paths($source, $remote_source = null, $upgrader = null, $hook_extra = null) {
			// This filter is global. Never alter packages uploaded through
			// Plugins > Add New or packages belonging to another plugin.
			if (is_array($hook_extra)) {
				if (isset($hook_extra['action']) && 'install' === $hook_extra['action']) {
					return $source;
				}

				if (
					isset($hook_extra['plugin'])
					&& plugin_basename(__FILE__) !== $hook_extra['plugin']
				) {
					return $source;
				}
			}

			if (!is_string($source) || !is_dir($source)) {
				return $source;
			}

			$base = rtrim($source, '/\\');
			$entries = glob($base . '/*');
			if (!is_array($entries) || empty($entries)) {
				return $source;
			}

			$has_package_marker = file_exists($base . '/mc-admissions-wordpress-backend.php')
				|| file_exists($base . '/mc-admissions-wordpress-backend/mc-admissions-wordpress-backend.php');
			$has_flattened_paths = false;

			foreach ($entries as $entry) {
				$name = basename($entry);
				if (false !== strpos($name, '\\')) {
					$has_flattened_paths = true;
					break;
				}
			}

			if (!$has_package_marker && !$has_flattened_paths) {
				return $source;
			}

			foreach ($entries as $entry) {
				$name = basename($entry);
				if (false === strpos($name, '\\')) {
					continue;
				}

				$relative = str_replace('\\', '/', $name);
				$relative = ltrim($relative, '/');
				if ('' === $relative || false !== strpos($relative, '../')) {
					continue;
				}

				$target = $base . '/' . $relative;
				$this->move_update_package_path($entry, $target);
			}

			$package_root = file_exists($base . '/mc-admissions-wordpress-backend.php')
				? $base
				: $base . '/mc-admissions-wordpress-backend';
			if (!file_exists($package_root . '/mc-admissions-wordpress-backend.php')) {
				return $source;
			}

			$installed_directory = dirname(plugin_basename(__FILE__));
			if ('.' === $installed_directory || '' === $installed_directory) {
				return $package_root;
			}

			if (basename($package_root) === $installed_directory) {
				return $package_root;
			}

			$target = dirname($package_root) . '/' . $installed_directory;
			if (file_exists($target)) {
				global $wp_filesystem;
				if (!is_object($wp_filesystem) || !$wp_filesystem->delete($target, true)) {
					return $package_root;
				}
			}

			if (
				$this->move_update_package_path($package_root, $target)
				&& file_exists($target . '/mc-admissions-wordpress-backend.php')
			) {
				return $target;
			}

			return $package_root;
		}

		private function move_update_package_path($source, $target) {
			$parent = dirname($target);
			if (!is_dir($parent) && !wp_mkdir_p($parent)) {
				return false;
			}

			if (file_exists($target)) {
				return true;
			}

			if (@rename($source, $target)) {
				return true;
			}

			if (is_dir($source)) {
				if (!wp_mkdir_p($target)) {
					return false;
				}
				$children = glob(rtrim($source, '/\\') . '/*');
				if (is_array($children)) {
					foreach ($children as $child) {
						$this->move_update_package_path($child, $target . '/' . basename($child));
					}
				}
				@rmdir($source);
				return true;
			}

			if (is_file($source) && @copy($source, $target)) {
				@unlink($source);
				return true;
			}

			return false;
		}

		public function register_admin_menu() {
			add_options_page(
				'MC Admissions',
				'MC Admissions',
				'manage_options',
				'mc-admissions',
				array($this, 'render_admin_page')
			);
		}

		public function render_admin_page() {
			if (!current_user_can('manage_options')) {
				wp_die(esc_html__('You do not have permission to access this page.', 'mc-admissions-wordpress-backend'));
			}

			$saved = false;

			if ('POST' === $_SERVER['REQUEST_METHOD']) {
				check_admin_referer('mc_admissions_save_settings');

				$settings = array(
					'm365_tenant_id' => $this->posted_setting('m365_tenant_id'),
					'm365_client_id' => $this->posted_setting('m365_client_id'),
					'm365_client_secret' => $this->posted_setting('m365_client_secret'),
					'm365_drive_id' => $this->posted_setting('m365_drive_id'),
					'm365_document_root' => $this->posted_setting('m365_document_root', 'Admissions'),
					'github_token' => $this->posted_setting('github_token'),
				);

				foreach ($settings as $key => $value) {
					$this->save_setting($key, $value);
				}

				$release_notification_secret = $this->posted_setting(self::RELEASE_NOTIFICATION_SECRET_SETTING);
				if ('' !== $release_notification_secret) {
					$this->save_setting(self::RELEASE_NOTIFICATION_SECRET_SETTING, $release_notification_secret);
				}

				$saved = true;
			}

			$tenant_id = $this->get_setting('m365_tenant_id');
			$client_id = $this->get_setting('m365_client_id');
			$client_secret = $this->get_setting('m365_client_secret');
			$drive_id = $this->get_setting('m365_drive_id');
			$document_root = $this->get_setting('m365_document_root', 'Admissions');
			$github_token = $this->get_setting('github_token');
			$release_notification_secret_configured = '' !== $this->get_setting(self::RELEASE_NOTIFICATION_SECRET_SETTING);
			$role_statuses = array_values($this->get_role_statuses());
			$missing_roles = array();
			$available_roles = array();

			foreach ($role_statuses as $role_status) {
				if (!empty($role_status['present'])) {
					$available_roles[] = $role_status['label'] . ' (' . $role_status['slug'] . ')';
				} else {
					$missing_roles[] = $role_status['label'] . ' (' . $role_status['slug'] . ')';
				}
			}

			$roles_ready = empty($missing_roles);
			?>
			<div class="wrap">
				<h1><?php echo esc_html__('MC Admissions Settings', 'mc-admissions-wordpress-backend'); ?></h1>
				<p><?php echo esc_html__('Store the Microsoft 365 document settings here so the desktop app never needs the SharePoint credentials locally.', 'mc-admissions-wordpress-backend'); ?></p>

				<?php if ($saved) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php echo esc_html__('Settings saved.', 'mc-admissions-wordpress-backend'); ?></p>
					</div>
				<?php endif; ?>

				<div class="notice <?php echo $roles_ready ? 'notice-success' : 'notice-warning'; ?>">
					<p>
						<strong><?php echo esc_html__('Admissions roles:', 'mc-admissions-wordpress-backend'); ?></strong>
						<?php
						echo $roles_ready
							? esc_html__('All admissions roles are ready in WordPress.', 'mc-admissions-wordpress-backend')
							: esc_html__('Some admissions roles are missing. Deactivate and reactivate the plugin or update it to the latest version.', 'mc-admissions-wordpress-backend');
						?>
					</p>
					<p>
						<?php
						echo $roles_ready
							? esc_html__('Available roles: ', 'mc-admissions-wordpress-backend') . esc_html(implode(', ', $available_roles))
							: esc_html__('Missing roles: ', 'mc-admissions-wordpress-backend') . esc_html(implode(', ', $missing_roles));
						?>
					</p>
					<p>
						<?php echo esc_html__('Give every external agent their own WordPress user with the mc_agent role, and assign internal staff to the matching admissions office roles before testing the live desktop workflow.', 'mc-admissions-wordpress-backend'); ?>
					</p>
				</div>

				<form method="post">
					<?php wp_nonce_field('mc_admissions_save_settings'); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="m365_tenant_id"><?php echo esc_html__('Microsoft 365 Tenant ID', 'mc-admissions-wordpress-backend'); ?></label></th>
								<td><input name="m365_tenant_id" id="m365_tenant_id" type="text" class="regular-text" value="<?php echo esc_attr($tenant_id); ?>" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="m365_client_id"><?php echo esc_html__('Microsoft 365 Client ID', 'mc-admissions-wordpress-backend'); ?></label></th>
								<td><input name="m365_client_id" id="m365_client_id" type="text" class="regular-text" value="<?php echo esc_attr($client_id); ?>" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="m365_client_secret"><?php echo esc_html__('Microsoft 365 Client Secret', 'mc-admissions-wordpress-backend'); ?></label></th>
								<td><input name="m365_client_secret" id="m365_client_secret" type="password" class="regular-text" value="<?php echo esc_attr($client_secret); ?>" autocomplete="new-password" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="m365_drive_id"><?php echo esc_html__('SharePoint Drive ID', 'mc-admissions-wordpress-backend'); ?></label></th>
								<td><input name="m365_drive_id" id="m365_drive_id" type="text" class="regular-text" value="<?php echo esc_attr($drive_id); ?>" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="m365_document_root"><?php echo esc_html__('Document Root Folder', 'mc-admissions-wordpress-backend'); ?></label></th>
								<td><input name="m365_document_root" id="m365_document_root" type="text" class="regular-text" value="<?php echo esc_attr($document_root); ?>" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="github_token"><?php echo esc_html__('GitHub Token (plugin auto-update)', 'mc-admissions-wordpress-backend'); ?></label></th>
								<td>
									<input name="github_token" id="github_token" type="password" class="regular-text" value="<?php echo esc_attr($github_token); ?>" autocomplete="new-password" />
									<p class="description"><?php echo esc_html__('Personal access token with read access to the private GitHub repository. Used by the auto-update checker to fetch new plugin releases.', 'mc-admissions-wordpress-backend'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="release_notification_secret"><?php echo esc_html__('Desktop Release Notification Secret', 'mc-admissions-wordpress-backend'); ?></label></th>
								<td>
									<input name="release_notification_secret" id="release_notification_secret" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($release_notification_secret_configured ? __('Configured - enter a new value to rotate', 'mc-admissions-wordpress-backend') : __('Enter the GitHub Actions webhook secret', 'mc-admissions-wordpress-backend')); ?>" />
									<p class="description"><?php echo esc_html__('Shared HMAC secret for signed desktop release notifications. Use the same value in the app release workflow. Leaving this field blank preserves the configured secret.', 'mc-admissions-wordpress-backend'); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button(__('Save settings', 'mc-admissions-wordpress-backend')); ?>
				</form>
			</div>
			<?php
		}

		public function register_rest_routes() {
			register_rest_route(
				self::API_NAMESPACE,
				'/health',
				array(
					'methods' => WP_REST_Server::READABLE,
					'callback' => array($this, 'rest_health'),
					'permission_callback' => '__return_true',
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/session',
				array(
					'methods' => WP_REST_Server::READABLE,
					'callback' => array($this, 'rest_session'),
					'permission_callback' => array($this, 'permission_authenticated'),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/account/password',
				array(
					'methods' => 'PUT',
					'callback' => array($this, 'rest_change_password'),
					'permission_callback' => array($this, 'permission_authenticated'),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/email',
				array(
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => array($this, 'rest_send_email'),
					'permission_callback' => array($this, 'permission_authenticated'),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/release-notification',
				array(
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => array($this, 'rest_release_notification'),
					'permission_callback' => '__return_true',
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/applications',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_list_applications'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => WP_REST_Server::CREATABLE,
						'callback' => array($this, 'rest_save_application'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => 'PATCH',
						'callback' => array($this, 'rest_update_workflow'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/notification-events',
				array(
					'methods' => WP_REST_Server::READABLE,
					'callback' => array($this, 'rest_notification_events'),
					'permission_callback' => array($this, 'permission_authenticated'),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/library',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_get_document_library'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/agent-media',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_list_agent_media'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => WP_REST_Server::CREATABLE,
						'callback' => array($this, 'rest_upload_agent_media'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/agent-media/(?P<media_id>[A-Za-z0-9_-]+)/file',
				array(
					'methods' => WP_REST_Server::READABLE,
					'callback' => array($this, 'rest_download_agent_media'),
					'permission_callback' => array($this, 'permission_authenticated'),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/applications/(?P<application_id>[A-Za-z0-9_-]+)',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_get_application'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => 'PATCH',
						'callback' => array($this, 'rest_update_operations'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/applications/(?P<application_id>[A-Za-z0-9_-]+)/documents',
				array(
					array(
						'methods' => WP_REST_Server::CREATABLE,
						'callback' => array($this, 'rest_upload_document'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => 'PATCH',
						'callback' => array($this, 'rest_update_document_assessments'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => 'DELETE',
						'callback' => array($this, 'rest_delete_document'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/applications/(?P<application_id>[A-Za-z0-9_-]+)/documents/(?P<document_id>[A-Za-z0-9_-]+)/file',
				array(
					'methods' => WP_REST_Server::READABLE,
					'callback' => array($this, 'rest_download_document_file'),
					'permission_callback' => array($this, 'permission_authenticated'),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/applications/(?P<application_id>[A-Za-z0-9_-]+)/letters/(?P<letter_id>[A-Za-z0-9_-]+)/file',
				array(
					'methods' => WP_REST_Server::READABLE,
					'callback' => array($this, 'rest_download_generated_letter_file'),
					'permission_callback' => array($this, 'permission_authenticated'),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/applications/(?P<application_id>[A-Za-z0-9_-]+)/payments',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_list_payments'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => WP_REST_Server::CREATABLE,
						'callback' => array($this, 'rest_create_payment'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/applications/(?P<application_id>[A-Za-z0-9_-]+)/migration',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_get_migration_case'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => WP_REST_Server::CREATABLE,
						'callback' => array($this, 'rest_upsert_migration_case'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/applications/(?P<application_id>[A-Za-z0-9_-]+)/immigration',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_get_immigration_case'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => WP_REST_Server::CREATABLE,
						'callback' => array($this, 'rest_upsert_immigration_case'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/agents',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_list_agents'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => WP_REST_Server::CREATABLE,
						'callback' => array($this, 'rest_create_agent'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);

			register_rest_route(
				self::API_NAMESPACE,
				'/profile',
				array(
					array(
						'methods' => WP_REST_Server::READABLE,
						'callback' => array($this, 'rest_get_profile'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
					array(
						'methods' => 'PUT',
						'callback' => array($this, 'rest_save_profile'),
						'permission_callback' => array($this, 'permission_authenticated'),
					),
				)
			);
		}

		public function send_rest_cors_headers($served, $result, $request, $server) {
			$origin = get_http_origin();

			if (!$origin || !$this->is_allowed_origin($origin)) {
				return $served;
			}

			header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
			header('Access-Control-Allow-Credentials: true');
			header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
			header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');
			header('Vary: Origin');

			if ('OPTIONS' === strtoupper($_SERVER['REQUEST_METHOD'])) {
				status_header(204);
				return true;
			}

			return $served;
		}

		public function disable_mc_admissions_rest_cache($result, $server, $request) {
			if (!$this->is_mc_admissions_rest_request($request)) {
				return $result;
			}

			if (!defined('DONOTCACHEPAGE')) {
				define('DONOTCACHEPAGE', true);
			}

			do_action(
				'litespeed_control_set_nocache',
				'Authenticated MC Admissions REST responses must never be cached.'
			);

			if (function_exists('nocache_headers')) {
				nocache_headers();
			}

			if (!headers_sent()) {
				header('X-LiteSpeed-Cache-Control: no-cache', true);
				header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
				header('Pragma: no-cache', true);
				header('Expires: Wed, 11 Jan 1984 05:00:00 GMT', true);
			}

			return $result;
		}

		public function add_mc_admissions_rest_no_cache_headers($response, $server, $request) {
			if (
				!$this->is_mc_admissions_rest_request($request) ||
				!is_object($response) ||
				!method_exists($response, 'header')
			) {
				return $response;
			}

			$response->header('X-LiteSpeed-Cache-Control', 'no-cache');
			$response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
			$response->header('Pragma', 'no-cache');
			$response->header('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');

			return $response;
		}

		private function is_mc_admissions_rest_request($request) {
			if (!is_object($request) || !method_exists($request, 'get_route')) {
				return false;
			}

			$route = (string) $request->get_route();
			$namespace = '/' . trim(self::API_NAMESPACE, '/');

			return $namespace === $route || 0 === strpos($route, $namespace . '/');
		}

		public function permission_authenticated() {
			if (!is_user_logged_in()) {
				return new WP_Error(
					'mc_admissions_not_authenticated',
					'Authentication required.',
					array('status' => 401)
				);
			}

			return true;
		}

		public function add_jwt_auth_epoch_claim($token, $user = null) {
			if (!is_array($token)) {
				return $token;
			}

			$user_id = is_object($user) && !empty($user->ID)
				? (int) $user->ID
				: $this->jwt_payload_user_id($token);
			if ($user_id <= 0) {
				return $token;
			}

			$token[self::AUTH_EPOCH_CLAIM] = $this->auth_epoch_for_user($user_id);

			return $token;
		}

		public function advance_auth_epoch_after_password_change($_password, $user_id, $_old_user_data = null) {
			$user_id = (int) $user_id;
			if ($user_id <= 0) {
				return;
			}

			if (!empty($this->password_epoch_preadvanced_user_ids[$user_id])) {
				return;
			}

			$current_epoch = $this->auth_epoch_for_user($user_id);
			update_user_meta($user_id, self::AUTH_EPOCH_META_KEY, $current_epoch + 1);
		}

		public function enforce_jwt_auth_epoch($user_id) {
			$this->jwt_auth_epoch_error = null;
			$bearer_token = $this->bearer_token_from_request();
			if (null === $bearer_token || (int) $user_id <= 0) {
				return $user_id;
			}

			$payload = $this->decode_validated_jwt_payload($bearer_token);
			$resolved_user_id = (int) $user_id;
			if (!is_array($payload) || $this->jwt_payload_user_id($payload) !== $resolved_user_id) {
				$this->jwt_auth_epoch_error = $this->revoked_session_error();
				return false;
			}

			$token_epoch = 0;
			if (array_key_exists(self::AUTH_EPOCH_CLAIM, $payload)) {
				$claim = $payload[self::AUTH_EPOCH_CLAIM];
				if (
					!(is_int($claim) && $claim >= 0)
					&& !(is_string($claim) && ctype_digit($claim))
				) {
					$this->jwt_auth_epoch_error = $this->revoked_session_error();
					return false;
				}

				$token_epoch = (int) $claim;
			}

			if ($token_epoch !== $this->auth_epoch_for_user($resolved_user_id)) {
				$this->jwt_auth_epoch_error = $this->revoked_session_error();
				return false;
			}

			return $user_id;
		}

		public function surface_jwt_auth_epoch_error($result) {
			return $this->jwt_auth_epoch_error instanceof WP_Error
				? $this->jwt_auth_epoch_error
				: $result;
		}

		private function auth_epoch_for_user($user_id) {
			$stored_epoch = get_user_meta((int) $user_id, self::AUTH_EPOCH_META_KEY, true);

			return is_numeric($stored_epoch) ? max(0, (int) $stored_epoch) : 0;
		}

		private function bearer_token_from_request() {
			$authorization = isset($_SERVER['HTTP_AUTHORIZATION'])
				? (string) $_SERVER['HTTP_AUTHORIZATION']
				: (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
					? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
					: '');

			if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
				return null;
			}

			return $matches[1];
		}

		private function jwt_payload_user_id($payload) {
			$user_id = is_array($payload)
				&& isset($payload['data'])
				&& is_array($payload['data'])
				&& isset($payload['data']['user'])
				&& is_array($payload['data']['user'])
				&& isset($payload['data']['user']['id'])
					? $payload['data']['user']['id']
					: null;

			return is_numeric($user_id) && (int) $user_id > 0 ? (int) $user_id : 0;
		}

		private function decode_validated_jwt_payload($token) {
			$parts = explode('.', (string) $token);
			if (3 !== count($parts) || strlen($parts[1]) > 16384) {
				return null;
			}

			$encoded_payload = strtr($parts[1], '-_', '+/');
			$remainder = strlen($encoded_payload) % 4;
			if (1 === $remainder) {
				return null;
			}

			if ($remainder > 0) {
				$encoded_payload .= str_repeat('=', 4 - $remainder);
			}

			$decoded_payload = base64_decode($encoded_payload, true);
			if (false === $decoded_payload) {
				return null;
			}

			$payload = json_decode($decoded_payload, true);

			return is_array($payload) ? $payload : null;
		}

		private function revoked_session_error() {
			return new WP_Error(
				'mc_admissions_session_revoked',
				'Your session has expired. Please sign in again.',
				array('status' => 401)
			);
		}

		public function rest_health() {
			$role_statuses = $this->get_role_statuses();
			$missing_roles = array();

			foreach ($role_statuses as $role_status) {
				if (empty($role_status['present'])) {
					$missing_roles[] = $role_status['slug'];
				}
			}

			$roles_ready = empty($missing_roles);
			$recent_post = get_posts(
				array(
					'numberposts' => 1,
					'post_status' => 'publish',
					'orderby' => 'date',
					'order' => 'DESC',
				)
			);

			$post = !empty($recent_post) ? $recent_post[0] : null;
			$agent_role = isset($role_statuses['mc_agent']) ? $role_statuses['mc_agent'] : array(
				'slug' => 'mc_agent',
				'label' => 'MC Agent',
				'present' => false,
			);

			return new WP_REST_Response(
				array(
					'ok' => (bool) $roles_ready,
					'apiBaseUrl' => untrailingslashit(rest_url()),
					'checkedAt' => gmdate('c'),
					'site' => array(
						'name' => get_bloginfo('name'),
						'description' => get_bloginfo('description'),
						'url' => home_url('/'),
						'home' => home_url('/'),
						'namespaceCount' => count(rest_get_server()->get_namespaces()),
						'hasJwtAuth' => in_array('jwt-auth/v1', rest_get_server()->get_namespaces(), true),
						'hasFluentFormApi' => in_array('fluentform/v1', rest_get_server()->get_namespaces(), true),
					),
					'samplePost' => $post
						? array(
							'id' => (int) $post->ID,
							'date' => get_post_time('c', true, $post),
							'slug' => $post->post_name,
							'title' => get_the_title($post),
						)
						: null,
					'backend' => array(
						'namespace' => self::API_NAMESPACE,
						'agentRole' => $agent_role,
						'workspaceRoles' => array_values($role_statuses),
					),
					'error' => $roles_ready
						? null
						: 'The following WordPress roles are missing: ' . implode(', ', $missing_roles) . '. Reactivate the MC Admissions plugin or update it to the latest version.',
				),
				200
			);
		}

		public function rest_session() {
			return new WP_REST_Response(
				array(
					'ok' => true,
					'source' => 'wordpress-jwt',
					'user' => $this->current_session_user(),
				),
				200
			);
		}

		public function rest_change_password(WP_REST_Request $request) {
			$params = $request->get_json_params();

			if (
				!is_array($params)
				|| !isset($params['currentPassword'], $params['newPassword'], $params['confirmPassword'])
				|| !is_string($params['currentPassword'])
				|| !is_string($params['newPassword'])
				|| !is_string($params['confirmPassword'])
				|| '' === $params['currentPassword']
				|| '' === $params['newPassword']
				|| '' === $params['confirmPassword']
			) {
				return $this->password_response(
					false,
					'Current password, new password, and password confirmation are required.',
					400
				);
			}

			$current_password = $params['currentPassword'];
			$new_password = $params['newPassword'];
			$confirm_password = $params['confirmPassword'];
			if (
				strlen($current_password) > 4096
				|| strlen($new_password) > 4096
				|| strlen($confirm_password) > 4096
			) {
				return $this->password_response(false, 'Password fields must not exceed 4096 characters.', 400);
			}

			if ($new_password !== $confirm_password) {
				return $this->password_response(false, 'New password and confirmation do not match.', 400);
			}

			$password_length = function_exists('mb_strlen')
				? mb_strlen($new_password)
				: strlen($new_password);
			if ($password_length < 12) {
				return $this->password_response(false, 'New password must contain at least 12 characters.', 400);
			}

			$current_user = wp_get_current_user();
			$user_id = isset($current_user->ID) ? (int) $current_user->ID : 0;
			$current_hash = isset($current_user->user_pass) ? (string) $current_user->user_pass : '';

			if ($user_id <= 0 || '' === $current_hash) {
				return $this->password_response(false, 'Authentication required.', 401);
			}

			$failed_attempts = $this->failed_password_attempt_count($user_id);
			if ($failed_attempts >= self::PASSWORD_ATTEMPT_LIMIT) {
				return $this->password_response(
					false,
					'Too many unsuccessful password attempts. Please try again later.',
					429
				);
			}

			if (!wp_check_password($current_password, $current_hash, $user_id)) {
				$failed_attempts = $this->record_failed_password_attempt($user_id, $failed_attempts);
				if ($failed_attempts >= self::PASSWORD_ATTEMPT_LIMIT) {
					return $this->password_response(
						false,
						'Too many unsuccessful password attempts. Please try again later.',
						429
					);
				}

				return $this->password_response(false, 'Current password is incorrect.', 400);
			}

			if (wp_check_password($new_password, $current_hash, $user_id)) {
				return $this->password_response(
					false,
					'New password must be different from the current password.',
					400
				);
			}

			$current_epoch = $this->auth_epoch_for_user($user_id);
			$next_epoch = $current_epoch + 1;
			update_user_meta($user_id, self::AUTH_EPOCH_META_KEY, $next_epoch);
			if ($this->auth_epoch_for_user($user_id) !== $next_epoch) {
				return $this->password_response(
					false,
					'Password could not be changed. Please try again.',
					500
				);
			}

			$this->password_epoch_preadvanced_user_ids[$user_id] = true;
			try {
				wp_set_password($new_password, $user_id);
				$updated_user = get_userdata($user_id);
				$updated_hash = is_object($updated_user) && isset($updated_user->user_pass)
					? (string) $updated_user->user_pass
					: '';
				if (
					'' === $updated_hash
					|| !wp_check_password($new_password, $updated_hash, $user_id)
				) {
					return $this->password_response(
						false,
						'Password could not be changed. Please try again.',
						500
					);
				}

				WP_Session_Tokens::get_instance($user_id)->destroy_all();
				wp_clear_auth_cookie();
			} catch (Throwable $error) {
				return $this->password_response(
					false,
					'Password could not be changed. Please try again.',
					500
				);
			} finally {
				unset($this->password_epoch_preadvanced_user_ids[$user_id]);
			}

			delete_transient($this->password_attempt_transient_key($user_id));

			return $this->password_response(true, 'Password changed successfully.', 200);
		}


		private function release_notification_error_response($code, $message, $status, $tag = null, $sent_count = 0, $failed_count = 0, $response_status = 'rejected') {
			return new WP_REST_Response(
				array(
					'ok' => false,
					'duplicate' => false,
					'status' => (string) $response_status,
					'tag' => $tag,
					'sentCount' => max(0, (int) $sent_count),
					'failedCount' => max(0, (int) $failed_count),
					'code' => (string) $code,
					'error' => (string) $message,
				),
				(int) $status
			);
		}

		private function release_notification_success_response($status, $tag, $duplicate, $sent_count = 0, $failed_count = 0, $http_status = 200) {
			return new WP_REST_Response(
				array(
					'ok' => true,
					'duplicate' => (bool) $duplicate,
					'status' => (string) $status,
					'tag' => (string) $tag,
					'sentCount' => max(0, (int) $sent_count),
					'failedCount' => max(0, (int) $failed_count),
				),
				(int) $http_status
			);
		}

		private function release_notification_state_key($repository, $tag) {
			return self::RELEASE_NOTIFICATION_STATE_PREFIX . substr(
				hash('sha256', (string) $repository . ':' . (string) $tag),
				0,
				48
			);
		}

		private function release_notification_lock_name($repository, $tag) {
			return self::RELEASE_NOTIFICATION_LOCK_PREFIX . substr(
				hash('sha256', (string) $repository . ':' . (string) $tag),
				0,
				32
			);
		}

		private function acquire_release_notification_lock($lock_name) {
			global $wpdb;

			$acquired = $wpdb->get_var(
				$wpdb->prepare('SELECT GET_LOCK(%s, 0)', (string) $lock_name)
			);

			return 1 === (int) $acquired;
		}

		private function release_release_notification_lock($lock_name) {
			global $wpdb;

			$wpdb->get_var(
				$wpdb->prepare('SELECT RELEASE_LOCK(%s)', (string) $lock_name)
			);
		}

		private function read_release_notification_state($state_key, $repository, $tag) {
			$empty_state = array(
				'repository' => (string) $repository,
				'tag' => (string) $tag,
				'complete' => false,
				'sentEmails' => array(),
				'attemptCount' => 0,
				'lastDeliveryId' => null,
				'updatedAt' => null,
			);
			$encoded = $this->get_setting($state_key);
			if ('' === $encoded) {
				return $empty_state;
			}

			$decoded = json_decode($encoded, true);
			if (
				!is_array($decoded)
				|| (string) ($decoded['repository'] ?? '') !== (string) $repository
				|| (string) ($decoded['tag'] ?? '') !== (string) $tag
			) {
				return $empty_state;
			}

			$sent_emails = array();
			foreach ((array) ($decoded['sentEmails'] ?? array()) as $email) {
				$email = sanitize_email((string) $email);
				if (is_email($email)) {
					$sent_emails[strtolower($email)] = true;
				}
			}

			return array(
				'repository' => (string) $repository,
				'tag' => (string) $tag,
				'complete' => !empty($decoded['complete']),
				'sentEmails' => array_keys($sent_emails),
				'attemptCount' => max(0, (int) ($decoded['attemptCount'] ?? 0)),
				'lastDeliveryId' => isset($decoded['lastDeliveryId'])
					? sanitize_text_field((string) $decoded['lastDeliveryId'])
					: null,
				'updatedAt' => isset($decoded['updatedAt'])
					? sanitize_text_field((string) $decoded['updatedAt'])
					: null,
			);
		}

		private function save_release_notification_state($state_key, $state) {
			$encoded = wp_json_encode($state);
			if (!is_string($encoded) || '' === $encoded) {
				return false;
			}

			return $this->save_setting($state_key, $encoded);
		}

		private function desktop_release_notification_payload($tag) {
			$marker = 'MC Admissions desktop update ' . (string) $tag . ' is available';
			$message = array(
				$marker . '.',
				'The app checks for updates automatically. When the update-ready prompt appears, choose Restart now to install it.',
			);

			return array(
				'roles' => array(
					'administrator',
					'admissions-officer',
					'finance-officer',
					'migration-officer',
					'immigration-officer',
					'registrar',
				),
				'to' => array(
					array(
						'email' => self::PRESIDENT_ACTIVITY_ALERT_EMAIL,
						'name' => self::PRESIDENT_ACTIVITY_ALERT_NAME,
					),
				),
				'subject' => $marker,
				'message' => implode("\n", $message),
			);
		}

		public function rest_release_notification(WP_REST_Request $request) {
			$secret = $this->get_setting(self::RELEASE_NOTIFICATION_SECRET_SETTING);
			if ('' === $secret) {
				return $this->release_notification_error_response(
					'release_notification_not_configured',
					'Desktop release notifications are not configured.',
					503
				);
			}

			$raw_body = (string) $request->get_body();
			if ('' === $raw_body) {
				return $this->release_notification_error_response(
					'empty_payload',
					'Release notification payload is required.',
					400
				);
			}
			if (strlen($raw_body) > 1024 * 1024) {
				return $this->release_notification_error_response(
					'payload_too_large',
					'Release notification payload is too large.',
					413
				);
			}

			$signature = strtolower(trim((string) $request->get_header('x-hub-signature-256')));
			$expected_signature = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);
			if (
				1 !== preg_match('/^sha256=[a-f0-9]{64}$/', $signature)
				|| !hash_equals($expected_signature, $signature)
			) {
				return $this->release_notification_error_response(
					'invalid_signature',
					'Release notification signature is invalid.',
					401
				);
			}

			if ('release' !== strtolower(trim((string) $request->get_header('x-github-event')))) {
				return $this->release_notification_error_response(
					'invalid_event',
					'Only published release events are accepted.',
					400
				);
			}

			$params = json_decode($raw_body, true);
			if (!is_array($params)) {
				return $this->release_notification_error_response(
					'invalid_json',
					'Release notification payload must be valid JSON.',
					400
				);
			}
			if ('published' !== (string) ($params['action'] ?? '')) {
				return $this->release_notification_error_response(
					'invalid_action',
					'Only the published release action is accepted.',
					400
				);
			}

			$repository = isset($params['repository']) && is_array($params['repository'])
				? $params['repository']
				: array();
			$repository_name = (string) ($repository['full_name'] ?? '');
			if (self::DESKTOP_RELEASE_REPOSITORY !== $repository_name) {
				return $this->release_notification_error_response(
					'invalid_repository',
					'Release notification repository is not allowed.',
					400
				);
			}

			$release = isset($params['release']) && is_array($params['release'])
				? $params['release']
				: array();
			$tag = (string) ($release['tag_name'] ?? '');
			if (1 !== preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $tag)) {
				return $this->release_notification_error_response(
					'invalid_tag',
					'Release tag must use semantic vX.Y.Z format.',
					400,
					$tag ?: null
				);
			}
			if (
				!array_key_exists('draft', $release)
				|| false !== $release['draft']
				|| !array_key_exists('prerelease', $release)
				|| false !== $release['prerelease']
			) {
				return $this->release_notification_error_response(
					'invalid_release_state',
					'Draft and prerelease builds are not eligible for staff notification.',
					400,
					$tag
				);
			}

			$version = substr($tag, 1);
			$required_assets = array(
				'mc-admissions-' . $version . '-win-x64.exe',
				'mc-admissions-' . $version . '-win-x64.exe.blockmap',
				'latest.yml',
			);
			$asset_names = array();
			foreach ((array) ($release['assets'] ?? array()) as $asset) {
				if (is_array($asset) && isset($asset['name'])) {
					$asset_names[] = (string) $asset['name'];
				}
			}
			$missing_assets = array_values(array_diff($required_assets, array_unique($asset_names)));
			if (!empty($missing_assets)) {
				return $this->release_notification_error_response(
					'missing_release_assets',
					'Release notification is missing required Windows update assets.',
					400,
					$tag
				);
			}

			$state_key = $this->release_notification_state_key($repository_name, $tag);
			$lock_name = $this->release_notification_lock_name($repository_name, $tag);
			if (!$this->acquire_release_notification_lock($lock_name)) {
				return $this->release_notification_error_response(
					'release_notification_in_progress',
					'This release notification is already being processed.',
					409,
					$tag
				);
			}

			try {
				$state = $this->read_release_notification_state($state_key, $repository_name, $tag);
				if (!empty($state['complete'])) {
					return $this->release_notification_success_response('duplicate', $tag, true);
				}

				$payload = $this->desktop_release_notification_payload($tag);
				$recipients = $this->resolve_email_recipients($payload);
				if (empty($recipients)) {
					return $this->release_notification_error_response(
						'no_release_recipients',
						'No internal release notification recipients were found.',
						502,
						$tag
					);
				}

				$sent_email_map = array();
				foreach ((array) $state['sentEmails'] as $sent_email) {
					$sent_email_map[strtolower((string) $sent_email)] = true;
				}
				$pending_recipients = array_values(
					array_filter(
						$recipients,
						static function ($recipient) use ($sent_email_map) {
							return empty($sent_email_map[strtolower((string) $recipient['email'])]);
						}
					)
				);
				if (empty($pending_recipients)) {
					$state['complete'] = true;
					$state['updatedAt'] = current_time('mysql', true);
					if (!$this->save_release_notification_state($state_key, $state)) {
						return $this->release_notification_error_response(
							'idempotency_storage_failed',
							'Release notification state could not be saved.',
							503,
							$tag
						);
					}
					return $this->release_notification_success_response('duplicate', $tag, true);
				}

				$state['attemptCount'] = max(0, (int) $state['attemptCount']) + 1;
				$state['lastDeliveryId'] = substr(
					sanitize_text_field((string) $request->get_header('x-github-delivery')),
					0,
					191
				);
				$state['updatedAt'] = current_time('mysql', true);
				if (!$this->save_release_notification_state($state_key, $state)) {
					return $this->release_notification_error_response(
						'idempotency_storage_failed',
						'Release notification state could not be saved.',
						503,
						$tag
					);
				}

				$headers = array('Content-Type: text/html; charset=UTF-8');
				$html_message = $this->build_email_message($payload['message']);
				$sent_count = 0;
				$failed_count = 0;

				foreach ($pending_recipients as $recipient) {
					try {
						$delivered = wp_mail(
							array($recipient['email']),
							$payload['subject'],
							$html_message,
							$headers
						);
					} catch (Throwable $mail_error) {
						$delivered = false;
					}

					if (!$delivered) {
						$failed_count++;
						continue;
					}

					$sent_count++;
					$sent_email_map[strtolower((string) $recipient['email'])] = true;
					$state['sentEmails'] = array_keys($sent_email_map);
					$state['updatedAt'] = current_time('mysql', true);
					if (!$this->save_release_notification_state($state_key, $state)) {
						return $this->release_notification_error_response(
							'idempotency_storage_failed',
							'Release notification state could not be saved.',
							503,
							$tag,
							$sent_count,
							count($pending_recipients) - $sent_count
						);
					}
				}

				$state['complete'] = 0 === $failed_count;
				$state['updatedAt'] = current_time('mysql', true);
				if (!$this->save_release_notification_state($state_key, $state)) {
					return $this->release_notification_error_response(
						'idempotency_storage_failed',
						'Release notification state could not be saved.',
						503,
						$tag,
						$sent_count,
						$failed_count
					);
				}

				if (0 === $failed_count) {
					return $this->release_notification_success_response(
						'sent',
						$tag,
						false,
						$sent_count,
						0
					);
				}

				return $this->release_notification_error_response(
					$sent_count > 0 ? 'release_notification_partial' : 'release_notification_failed',
					$sent_count > 0
						? 'Desktop release notification was only partially delivered.'
						: 'Desktop release notification could not be delivered.',
					502,
					$tag,
					$sent_count,
					$failed_count,
					$sent_count > 0 ? 'partial' : 'failed'
				);
			} finally {
				$this->release_release_notification_lock($lock_name);
			}
		}

		public function rest_send_email(WP_REST_Request $request) {
			$params = $request->get_json_params();

			if (!is_array($params)) {
				return $this->json_error_response('Email payload is required.', 400);
			}

			$subject = isset($params['subject']) ? sanitize_text_field((string) $params['subject']) : '';
			$message = isset($params['message']) ? (string) $params['message'] : '';

			if ('' === $subject || '' === trim($message)) {
				return $this->json_error_response('Email subject and message are required.', 400);
			}

			try {
				$user = $this->current_session_user();
				$recipients = $this->resolve_email_recipients($params);

				if (empty($recipients)) {
					return $this->json_error_response('No valid email recipients were found.', 400);
				}

				$attachments = $this->create_email_attachments(
					isset($params['attachments']) && is_array($params['attachments'])
						? $params['attachments']
						: array()
				);
				$headers = array('Content-Type: text/html; charset=UTF-8');

				if (!empty($user['email']) && is_email($user['email'])) {
					$headers[] = sprintf(
						'Reply-To: %s <%s>',
						$this->sanitize_mail_header_name($user['name']),
						$user['email']
					);
				}

				$html_message = $this->build_email_message(
					$message,
					isset($params['application']) && is_array($params['application']) ? $params['application'] : null
				);
				$sent = array();
				$failed = array();

				foreach ($recipients as $recipient) {
					$delivered = wp_mail(
						array($recipient['email']),
						$subject,
						$html_message,
						$headers,
						$attachments
					);

					if ($delivered) {
						$sent[] = $recipient;
					} else {
						$failed[] = $recipient;
					}
				}

				$this->delete_temp_files($attachments);

				return new WP_REST_Response(
					array(
						'ok' => !empty($sent),
						'sent' => $sent,
						'failed' => $failed,
						'error' => empty($sent) ? 'WordPress wp_mail did not accept the message.' : null,
					),
					empty($sent) ? 502 : 200
				);
			} catch (Exception $error) {
				if (!empty($attachments) && is_array($attachments)) {
					$this->delete_temp_files($attachments);
				}

				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		private function resolve_email_recipients($params) {
			$recipients = array();

			if (!empty($params['to']) && is_array($params['to'])) {
				foreach ($params['to'] as $entry) {
					if (is_string($entry)) {
						$this->add_email_recipient($recipients, $entry, null, null);
					} elseif (is_array($entry)) {
						$this->add_email_recipient(
							$recipients,
							isset($entry['email']) ? (string) $entry['email'] : '',
							isset($entry['name']) ? (string) $entry['name'] : null,
							isset($entry['role']) ? (string) $entry['role'] : null
						);
					}
				}
			}

			if (!empty($params['roles']) && is_array($params['roles'])) {
				$allowed_roles = array_merge(array_keys($this->admissions_role_definitions()), array('administrator'));
				$roles = array();

				foreach ($params['roles'] as $role) {
					$role = sanitize_key((string) $role);

					if (in_array($role, $allowed_roles, true)) {
						$roles[] = $role;
					}
				}

				$roles = array_values(array_unique($roles));

				if (!empty($roles)) {
					$users = get_users(
						array(
							'role__in' => $roles,
							'fields' => array('ID', 'display_name', 'user_email'),
						)
					);

					foreach ($users as $user) {
						$wp_user = get_userdata($user->ID);
						$user_roles = $wp_user ? array_values(array_intersect($roles, (array) $wp_user->roles)) : array();

						$this->add_email_recipient(
							$recipients,
							$user->user_email,
							$user->display_name,
							!empty($user_roles) ? $user_roles[0] : null
						);
					}
				}
			}

			return array_values($recipients);
		}

		private function add_email_recipient(&$recipients, $email, $name = null, $role = null) {
			$email = sanitize_email((string) $email);

			if (!is_email($email)) {
				return;
			}

			$key = strtolower($email);
			$recipients[$key] = array(
				'email' => $email,
				'name' => $this->trim_to_null($name),
				'role' => $this->trim_to_null($role),
			);
		}

		private function has_application_test_data_marker($value) {
			$normalized = strtolower(trim((string) $value));

			if ('' === $normalized) {
				return false;
			}

			return false !== strpos($normalized, 'local.invalid')
				|| false !== strpos($normalized, '.example')
				|| false !== strpos($normalized, '.test')
				|| 1 === preg_match('/\b(test|verification|smoke|codex|uat)\b/i', $normalized);
		}

		private function infer_application_test_data($draft, $user) {
			if (isset($user['id']) && (int) $user['id'] < 0) {
				return true;
			}

			$values = array(
				isset($user['username']) ? $user['username'] : null,
				isset($user['name']) ? $user['name'] : null,
				isset($user['email']) ? $user['email'] : null,
				isset($draft['fullName']) ? $draft['fullName'] : null,
				isset($draft['passportNumber']) ? $draft['passportNumber'] : null,
				isset($draft['email']) ? $draft['email'] : null,
				isset($draft['agencyName']) ? $draft['agencyName'] : null,
				isset($draft['consultantName']) ? $draft['consultantName'] : null,
				isset($draft['consultantEmail']) ? $draft['consultantEmail'] : null,
			);

			foreach ($values as $value) {
				if ($this->has_application_test_data_marker($value)) {
					return true;
				}
			}

			return false;
		}

		private function resolve_application_test_data($draft, $user, $requested = null, $current = false) {
			return !empty($current)
				|| (true === $requested && $this->is_admin_user($user))
				|| $this->infer_application_test_data($draft, $user);
		}

		private function should_send_review_submission_alert($application, $user, $was_submitted_for_review) {
			return (bool) $was_submitted_for_review
				&& $this->is_external_agent_user($user)
				&& empty($application['isTestData'])
				&& 'review-pending' === $this->canonical_status_key(isset($application['status']) ? (string) $application['status'] : '');
		}

		private function should_send_post_submission_agent_document_alert($application, $user) {
			if (!$this->is_external_agent_user($user) || !empty($application['isTestData'])) {
				return false;
			}

			$status = isset($application['status']) ? (string) $application['status'] : '';

			return 'profile-preparation' !== $this->canonical_status_key($status);
		}

		private function application_activity_alert_payload($application, $user, $event_type, $document_type = null, $file_name = null) {
			$reference = isset($application['referenceCode']) ? (string) $application['referenceCode'] : '';
			$full_name = isset($application['fullName']) ? (string) $application['fullName'] : '';
			$student_label = implode(' / ', array_filter(array($reference, $full_name)));
			$actor_name = isset($user['name']) ? (string) $user['name'] : 'An admissions user';
			$roles = array('administrator', 'admissions-officer', 'immigration-officer');

			if ('agent-document-uploaded' === $event_type && 'bankTransactionConfirmation' === $document_type) {
				$roles[] = 'finance-officer';
			}

			if ('new-application-submitted' === $event_type) {
				$subject = sanitize_text_field('New application submitted: ' . $student_label);
				$message = implode(
					"\n",
					array(
						'A new application was submitted to Admissions and is ready for review.',
						'Submitted by: ' . $actor_name . '.',
						'Please open MC Admissions and review the case.',
					)
				);
			} else {
				$document_label = isset($this->document_requirements[$document_type])
					? $this->document_requirements[$document_type]
					: 'Application document';
				$subject = sanitize_text_field('Agent document uploaded: ' . $student_label);
				$message_parts = array(
					$actor_name . ' uploaded or replaced a document after the application was submitted.',
					'Document: ' . $document_label . '.',
				);
				if (null !== $file_name && '' !== trim((string) $file_name)) {
					$message_parts[] = 'File: ' . (string) $file_name . '.';
				}
				$message_parts[] = 'Please open MC Admissions and review the updated document.';
				$message = implode("\n", $message_parts);
			}

			return array(
				'roles' => array_values(array_unique($roles)),
				'to' => array(
					array(
						'email' => self::PRESIDENT_ACTIVITY_ALERT_EMAIL,
						'name' => self::PRESIDENT_ACTIVITY_ALERT_NAME,
					),
				),
				'subject' => $subject,
				'message' => $message,
				'application' => array(
					'id' => isset($application['id']) ? (string) $application['id'] : null,
					'referenceCode' => $reference,
					'fullName' => $full_name,
				),
			);
		}

		private function record_application_activity_alert($application_id, $user, $payload, $sent, $failed, $error = null) {
			global $wpdb;

			$sent_count = count((array) $sent);
			$failed_count = count((array) $failed);
			if ($sent_count > 0 && 0 === $failed_count) {
				$delivery_status = sprintf('Email delivery: sent to %d recipient(s).', $sent_count);
			} elseif ($sent_count > 0) {
				$delivery_status = sprintf(
					'Email delivery: partially sent to %d recipient(s); %d failed.',
					$sent_count,
					$failed_count
				);
			} else {
				$delivery_status = 'Email delivery failed: ' . ($error ? (string) $error : 'WordPress did not accept the message.');
			}

			$detail = implode(
				"\n",
				array(
					(string) $payload['message'],
					'Recipient roles: ' . implode(', ', (array) $payload['roles']) . '.',
					$delivery_status,
				)
			);

			try {
				if ($this->table_exists($this->communications_table)) {
					$wpdb->insert(
						$this->communications_table,
						array(
							'id' => wp_generate_uuid4(),
							'applicationId' => $application_id,
							'direction' => 'outbound',
							'channel' => 'email',
							'subject' => (string) $payload['subject'],
							'detail' => $detail,
							'actorName' => isset($user['name']) ? (string) $user['name'] : 'MC Admissions',
							'createdAt' => current_time('mysql', true),
						),
						array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
					);
				}

				$this->create_activity(
					$application_id,
					$user,
					'communication',
					(string) $payload['subject'],
					$delivery_status
				);
			} catch (Throwable $audit_error) {
				error_log('MC Admissions could not record urgent email delivery: ' . $audit_error->getMessage());
			}
		}

		private function send_application_activity_alert($application, $user, $event_type, $document_type = null, $file_name = null) {
			if (!empty($application['isTestData'])) {
				return array('ok' => false, 'skipped' => true, 'sent' => array(), 'failed' => array());
			}

			$payload = $this->application_activity_alert_payload(
				$application,
				$user,
				$event_type,
				$document_type,
				$file_name
			);
			$recipients = array();
			$sent = array();
			$failed = array();
			$error_message = null;

			try {
				$recipients = $this->resolve_email_recipients($payload);
				if (empty($recipients)) {
					throw new Exception('No valid urgent-alert email recipients were found.');
				}

				$headers = array('Content-Type: text/html; charset=UTF-8');
				if (!empty($user['email']) && is_email($user['email'])) {
					$headers[] = sprintf(
						'Reply-To: %s <%s>',
						$this->sanitize_mail_header_name($user['name']),
						$user['email']
					);
				}
				$html_message = $this->build_email_message($payload['message'], $payload['application']);

				foreach ($recipients as $recipient) {
					try {
						$delivered = wp_mail(
							array($recipient['email']),
							$payload['subject'],
							$html_message,
							$headers
						);
					} catch (Throwable $mail_error) {
						$delivered = false;
						$error_message = $mail_error->getMessage();
					}
					if ($delivered) {
						$sent[] = $recipient;
					} else {
						$failed[] = $recipient;
					}
				}
			} catch (Throwable $delivery_error) {
				$error_message = $delivery_error->getMessage();
			}

			$this->record_application_activity_alert(
				isset($application['id']) ? (string) $application['id'] : '',
				$user,
				$payload,
				$sent,
				$failed,
				$error_message
			);

			return array(
				'ok' => !empty($sent) && empty($failed),
				'sent' => $sent,
				'failed' => $failed,
				'error' => $error_message,
			);
		}

		private function create_email_attachments($attachments) {
			$paths = array();
			$total_bytes = 0;
			$temp_dir = trailingslashit(get_temp_dir());

			foreach (array_slice($attachments, 0, 10) as $attachment) {
				if (!is_array($attachment) || empty($attachment['contentBase64'])) {
					continue;
				}

				$file_name = !empty($attachment['fileName'])
					? sanitize_file_name((string) $attachment['fileName'])
					: 'attachment.bin';

				if ('' === $file_name) {
					$file_name = 'attachment.bin';
				}

				$content = preg_replace('/^data:[^;]+;base64,/', '', (string) $attachment['contentBase64']);
				$decoded = base64_decode($content, true);

				if (false === $decoded) {
					throw new Exception('Email attachment could not be decoded.');
				}

				$total_bytes += strlen($decoded);

				if ($total_bytes > 15 * 1024 * 1024) {
					throw new Exception('Email attachments exceed the 15 MB limit.');
				}

				$path = $temp_dir . wp_unique_filename($temp_dir, $file_name);

				if (false === file_put_contents($path, $decoded)) {
					throw new Exception('Email attachment could not be prepared.');
				}

				$paths[] = $path;
			}

			return $paths;
		}

		private function build_email_message($message, $application = null) {
			$parts = array();
			$parts[] = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#202124">';
			$parts[] = '<div>' . nl2br(esc_html((string) $message)) . '</div>';

			if (is_array($application)) {
				$reference = isset($application['referenceCode'])
					? $this->trim_to_null($application['referenceCode'])
					: null;
				$full_name = isset($application['fullName'])
					? $this->trim_to_null($application['fullName'])
					: null;

				if ($reference || $full_name) {
					$application_label = implode(
						' / ',
						array_filter(array($reference, $full_name))
					);

					$parts[] = '<hr style="border:none;border-top:1px solid #dadce0;margin:16px 0" />';
					$parts[] = '<p style="margin:0;color:#5f6368">';
					$parts[] = '<strong>Application:</strong> ' . esc_html($application_label);
					$parts[] = '</p>';
				}
			}

			$parts[] = '</div>';

			return implode('', $parts);
		}

		private function sanitize_mail_header_name($name) {
			return trim(str_replace(array("\r", "\n"), '', wp_strip_all_tags((string) $name)));
		}

		private function delete_temp_files($paths) {
			foreach ((array) $paths as $path) {
				if (is_string($path) && file_exists($path)) {
					@unlink($path);
				}
			}
		}

		// Count generated letters of a given template for the dashboard summary.
		// Staff see all; agents are scoped to their own applications. Returns 0
		// defensively if the Prisma-managed letters table is not present.
		private function count_generated_letters($user, $template_id) {
			global $wpdb;

			$letters_table = 'mc_generated_letters';
			$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $letters_table));

			if ($exists !== $letters_table) {
				return 0;
			}

			if ($this->can_view_all_applications($user)) {
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT letter.applicationId) FROM {$letters_table} letter
						INNER JOIN {$this->applications_table} app ON app.id = letter.applicationId
						WHERE letter.templateId = %s AND app.status <> 'trashed'",
						$template_id
					)
				);
			}

			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT letter.applicationId) FROM {$letters_table} letter
					INNER JOIN {$this->applications_table} app ON app.id = letter.applicationId
					WHERE letter.templateId = %s AND app.wordpressUserId = %d AND app.status <> 'trashed'",
					$template_id,
					(int) $user['id']
				)
			);
		}

		// Aggregated document library: generated letters + ready uploaded
		// documents across the user's visible applications. Returns raw rows;
		// the Next side (listAgentAdmissionDocumentLibrary) builds the snapshot.
		public function rest_get_document_library() {
			global $wpdb;

			try {
				$user = $this->current_session_user();

				$where = '';
				$args = array();
				if (!$this->can_view_all_applications($user)) {
					$where = 'WHERE wordpressUserId = %d';
					$args[] = (int) $user['id'];
				}
				$args[] = 50;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$apps = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, referenceCode, fullName, agencyName FROM {$this->applications_table} {$where} ORDER BY updatedAt DESC LIMIT %d",
						$args
					),
					ARRAY_A
				);
				$apps = is_array($apps) ? $apps : array();

				$letters_table = 'mc_generated_letters';
				$has_letters =
					$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $letters_table)) === $letters_table;

				foreach ($apps as &$app) {
					$aid = $app['id'];

					$letters = array();
					if ($has_letters) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$letters = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT id, applicationId, templateLabel, fileName, createdAt, generatedByName FROM {$letters_table} WHERE applicationId = %s ORDER BY createdAt DESC LIMIT 8",
								$aid
							),
							ARRAY_A
						);
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$docs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT id, label, originalName, uploadedByName, uploadedAt, createdAt, mimeType, uploadedUrl FROM {$this->documents_table} WHERE applicationId = %s AND isReady = 1 ORDER BY updatedAt DESC, createdAt DESC LIMIT 12",
							$aid
						),
						ARRAY_A
					);

					$app['generatedLetters'] = is_array($letters) ? $letters : array();
					$app['documents'] = is_array($docs) ? $docs : array();
				}
				unset($app);

				return new WP_REST_Response(array('ok' => true, 'applications' => $apps), 200);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_list_agent_media() {
			try {
				$user = $this->current_session_user();
				if (!$this->can_access_agent_media($user)) {
					return $this->json_error_response('Documents access is restricted to Administrators and Agents.', 403);
				}

				return new WP_REST_Response(
					array(
						'ok' => true,
						'media' => $this->public_agent_media_records($this->get_agent_media_records()),
					),
					200
				);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_upload_agent_media(WP_REST_Request $request) {
			try {
				$user = $this->current_session_user();
				if (!$this->is_admin_user($user)) {
					return $this->json_error_response('Administrator access required.', 403);
				}

				$files = $request->get_file_params();
				$file = isset($files['file']) ? $files['file'] : null;
				$category = sanitize_key((string) $request->get_param('category'));
				$replace_id = sanitize_text_field((string) $request->get_param('replaceId'));
				if (empty($file) || empty($file['tmp_name'])) {
					throw new Exception('Choose a file to upload.');
				}
				if (!in_array($category, array('admission-policy', 'about-mesoyios', 'marketing', 'other'), true)) {
					throw new Exception('Invalid media category.');
				}

				$file_name = sanitize_file_name(isset($file['name']) ? (string) $file['name'] : 'upload.bin');
				$mime_type = !empty($file['type']) ? sanitize_mime_type((string) $file['type']) : 'application/octet-stream';
				$file_size = isset($file['size']) ? (int) $file['size'] : 0;
				$this->validate_agent_media_file($category, $file_name, $mime_type, $file_size);

				$records = $this->get_agent_media_records();
				$old_record = null;
				if (in_array($category, array('admission-policy', 'about-mesoyios'), true)) {
					foreach ($records as $record) {
						if ($record['category'] === $category) {
							$old_record = $record;
							$replace_id = $record['id'];
							break;
						}
					}
				} elseif ('' !== $replace_id) {
					foreach ($records as $record) {
						if ($record['id'] === $replace_id && $category === $record['category']) {
							$old_record = $record;
							break;
						}
					}
					if (null === $old_record) {
						throw new Exception('The item to replace was not found.');
					}
				}

				$id = '' !== $replace_id ? $replace_id : wp_generate_uuid4();
				$stored = $this->store_document_file('_agent-library', $id, $file_name, $mime_type, (string) $file['tmp_name']);
				$label = trim(sanitize_text_field((string) $request->get_param('label')));
				if ('' === $label) {
					$label = 'admission-policy' === $category ? 'Admission Policy' : ('about-mesoyios' === $category ? 'About Mesoyios College' : pathinfo($file_name, PATHINFO_FILENAME));
				}
				$new_record = array_merge(
					array(
						'id' => $id,
						'category' => $category,
						'label' => $label,
						'fileName' => $file_name,
						'mimeType' => $mime_type,
						'fileSize' => $file_size,
						'uploadedAt' => gmdate('c'),
						'uploadedByName' => $user['name'],
					),
					$stored
				);

				$records = array_values(array_filter($records, function ($record) use ($id) { return $record['id'] !== $id; }));
				$records[] = $new_record;
				$this->save_setting('agent_document_media', wp_json_encode($records));
				if ($old_record) {
					$this->delete_document_file($old_record['storageDriveId'], $old_record['storageItemId']);
				}

				return new WP_REST_Response(
					array('ok' => true, 'media' => $this->public_agent_media_records($records)),
					200
				);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_download_agent_media(WP_REST_Request $request) {
			try {
				$user = $this->current_session_user();
				if (!$this->can_access_agent_media($user)) {
					return $this->json_error_response('Documents access is restricted to Administrators and Agents.', 403);
				}
				$record = $this->find_agent_media_record((string) $request['media_id']);
				$response = $this->download_document_file($record['storageDriveId'], $record['storageItemId']);
				$body = wp_remote_retrieve_body($response);
				if (empty($body)) {
					throw new Exception('Media file not found.');
				}
				status_header(200);
				header('Content-Type: ' . (!empty($record['mimeType']) ? $record['mimeType'] : 'application/octet-stream'));
				header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($record['fileName']));
				header('Cache-Control: private, no-store');
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 404);
			}
		}

		public function rest_list_applications() {
			try {
				$user = $this->current_session_user();
				$applications = $this->list_admission_board_applications($user);

				return new WP_REST_Response(
					array(
						'ok' => true,
						'applications' => $applications,
						'offerLetterCount' => $this->count_generated_letters($user, 'offer-letter'),
						'acceptanceLetterCount' => $this->count_generated_letters($user, 'acceptance-letter'),
					),
					200
				);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		private function current_notification_event_mysql_datetime() {
			return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
		}

		private function encode_notification_event_cursor($created_at, $event_id = '') {
			$created_at_iso = $this->mysql_datetime_to_iso($created_at);
			if (empty($created_at_iso)) {
				throw new Exception('Unable to create the notification cursor.');
			}

			return $created_at_iso . '|' . (string) $event_id;
		}

		private function parse_notification_event_cursor($value, $fallback_mysql) {
			$value = trim((string) $value);
			if ('' === $value) {
				return array(
					'createdAt' => $fallback_mysql,
					'id' => '',
				);
			}

			$parts = explode('|', $value, 2);
			try {
				$date = new DateTimeImmutable($parts[0], new DateTimeZone('UTC'));
			} catch (Exception $error) {
				return null;
			}

			$event_id = isset($parts[1]) ? trim((string) $parts[1]) : '';
			if ('' !== $event_id && 1 !== preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,190}$/', $event_id)) {
				return null;
			}

			return array(
				'createdAt' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
				'id' => $event_id,
			);
		}

		public function rest_notification_events(WP_REST_Request $request) {
			global $wpdb;

			try {
				$user = $this->current_session_user();
				$upper_bound_mysql = $this->current_notification_event_mysql_datetime();
				$empty_cursor = $this->encode_notification_event_cursor($upper_bound_mysql);

				if (!$this->can_view_all_applications($user)) {
					return new WP_REST_Response(
						array(
							'ok' => true,
							'cursor' => $empty_cursor,
							'events' => array(),
							'hasMore' => false,
						),
						200
					);
				}

				$since_value = sanitize_text_field((string) $request->get_param('since'));
				$since = $this->parse_notification_event_cursor($since_value, $upper_bound_mysql);
				if (null === $since) {
					return $this->json_error_response('Invalid notification cursor.', 400);
				}

				$query_args = array();
				if ('' === $since['id']) {
					$cursor_filter_sql = 'activity.createdAt >= %s';
					$query_args[] = $since['createdAt'];
				} else {
					$cursor_filter_sql = '(activity.createdAt > %s OR (activity.createdAt = %s AND activity.id > %s))';
					$query_args[] = $since['createdAt'];
					$query_args[] = $since['createdAt'];
					$query_args[] = $since['id'];
				}
				$query_args[] = $upper_bound_mysql;
				$query_args[] = self::NOTIFICATION_EVENT_PAGE_SIZE;

				// Only the durable agent-document-upload kind is sound-eligible.
				// Ordinary document timeline rows include preparation uploads,
				// assessments, and removals and must never enter this feed.
				$notification_document_kind = self::NOTIFICATION_DOCUMENT_ACTIVITY_KIND;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT activity.id, activity.applicationId, activity.kind, activity.title,
						activity.actorName, activity.createdAt, app.referenceCode, app.fullName
						FROM {$this->activities_table} activity
						INNER JOIN {$this->applications_table} app ON app.id = activity.applicationId
						WHERE activity.actorRole = 'agent'
						AND {$cursor_filter_sql}
						AND activity.createdAt <= %s
						AND activity.kind IN ('workflow', '{$notification_document_kind}')
						AND COALESCE(app.isTestData, 0) = 0
						ORDER BY activity.createdAt ASC, activity.id ASC
						LIMIT %d",
						$query_args
					),
					ARRAY_A
				);

				if (!is_array($rows)) {
					return $this->json_error_response('Unable to load notification events.', 503);
				}

				$rows = array_slice($rows, 0, self::NOTIFICATION_EVENT_PAGE_SIZE);
				$has_more = self::NOTIFICATION_EVENT_PAGE_SIZE === count($rows);
				$events = array_map(
					function ($row) {
						return array(
							'id' => $row['id'],
							'type' => self::NOTIFICATION_DOCUMENT_ACTIVITY_KIND === $row['kind']
								? 'document-uploaded'
								: ('workflow' === $row['kind'] ? 'application-submitted' : 'application-updated'),
							'applicationId' => $row['applicationId'],
							'referenceCode' => $row['referenceCode'],
							'applicantName' => $row['fullName'],
							'title' => $row['title'],
							'actorName' => $row['actorName'],
							'createdAt' => $this->mysql_datetime_to_iso($row['createdAt']),
						);
					},
					$rows
				);

				$cursor = $empty_cursor;
				if ($has_more) {
					$last_row = $rows[count($rows) - 1];
					$cursor = $this->encode_notification_event_cursor($last_row['createdAt'], $last_row['id']);
				}

				return new WP_REST_Response(
					array(
						'ok' => true,
						'cursor' => $cursor,
						'events' => $events,
						'hasMore' => $has_more,
					),
					200
				);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_get_profile() {
			global $wpdb;

			$user_id = get_current_user_id();
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->agency_profiles_table} WHERE wordpressUserId = %d LIMIT 1",
					$user_id
				),
				ARRAY_A
			);

			$current_user = wp_get_current_user();

			if (!$row) {
				return new WP_REST_Response(
					array(
						'ok'      => true,
						'profile' => array(
							'id'                     => null,
							'source'                 => 'session-default',
							'agencyName'             => '',
							'consultantName'         => $current_user->display_name,
							'consultantEmail'        => $current_user->user_email,
							'consultantPhone'        => '',
							'defaultApplicationRoute' => 'standard',
							'agreementOnFile'        => false,
							'authorizationOnFile'    => false,
							'notes'                  => '',
							'updatedAt'              => null,
						),
					),
					200
				);
			}

			if (
				strtolower((string) ($row['wordpressEmail'] ?? '')) !== strtolower((string) $current_user->user_email)
				|| strtolower((string) ($row['consultantEmail'] ?? '')) !== strtolower((string) $current_user->user_email)
			) {
				$wpdb->update(
					$this->agency_profiles_table,
					array(
						'wordpressEmail'  => $current_user->user_email,
						'consultantEmail' => $current_user->user_email,
						'updatedAt'       => current_time('mysql', true),
					),
					array('wordpressUserId' => $user_id)
				);

				$row['wordpressEmail']  = $current_user->user_email;
				$row['consultantEmail'] = $current_user->user_email;
				$row['updatedAt']       = current_time('mysql', true);
			}

			return new WP_REST_Response(
				array(
					'ok'      => true,
					'profile' => array(
						'id'                     => $row['id'],
						'source'                 => 'saved',
						'agencyName'             => $row['agencyName'],
						'consultantName'         => $row['consultantName'],
						'consultantEmail'        => $current_user->user_email,
						'consultantPhone'        => $row['consultantPhone'] ?? '',
						'defaultApplicationRoute' => $row['defaultApplicationRoute'] ?? 'standard',
						'agreementOnFile'        => !empty($row['agreementOnFile']),
						'authorizationOnFile'    => !empty($row['authorizationOnFile']),
						'notes'                  => $row['notes'] ?? '',
						'updatedAt'              => $row['updatedAt'] ?? null,
					),
				),
				200
			);
		}

		public function rest_save_profile(WP_REST_Request $request) {
			global $wpdb;

			$params = $request->get_json_params();
			$draft  = isset($params['draft']) ? (array) $params['draft'] : array();

			$agency_name      = isset($draft['agencyName']) ? trim($draft['agencyName']) : '';
			$consultant_name  = isset($draft['consultantName']) ? trim($draft['consultantName']) : '';

			if (empty($agency_name)) {
				return $this->json_error_response('Agency name is required.', 400);
			}

			if (empty($consultant_name)) {
				return $this->json_error_response('Consultant name is required.', 400);
			}

			$user_id          = get_current_user_id();
			$current_user     = wp_get_current_user();
			$consultant_email = isset($draft['consultantEmail']) ? sanitize_email((string) $draft['consultantEmail']) : '';

			if ('' !== $consultant_email && !is_email($consultant_email)) {
				return $this->json_error_response('Consultant email must be a valid email address.', 400);
			}

			if ('' !== $consultant_email && strtolower($consultant_email) !== strtolower((string) $current_user->user_email)) {
				$existing_email_user_id = email_exists($consultant_email);

				if ($existing_email_user_id && (int) $existing_email_user_id !== (int) $user_id) {
					return $this->json_error_response('That email address is already used by another WordPress account.', 400);
				}

				$email_update = wp_update_user(
					array(
						'ID'         => $user_id,
						'user_email' => $consultant_email,
					)
				);

				if (is_wp_error($email_update)) {
					return $this->json_error_response($email_update->get_error_message(), 400);
				}

				clean_user_cache($user_id);
				$current_user = get_userdata($user_id);
			}

			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->agency_profiles_table} WHERE wordpressUserId = %d LIMIT 1",
					$user_id
				)
			);

			$route = isset($draft['defaultApplicationRoute']) && $draft['defaultApplicationRoute'] === 'postgraduate'
				? 'postgraduate'
				: 'standard';

			$data = array(
				'wordpressUsername'      => $current_user->user_login,
				'wordpressEmail'         => $current_user->user_email,
				'agencyName'             => $agency_name,
				'consultantName'         => $consultant_name,
				'consultantEmail'        => '' !== $consultant_email ? $consultant_email : null,
				'consultantPhone'        => isset($draft['consultantPhone']) ? trim($draft['consultantPhone']) : null,
				'defaultApplicationRoute' => $route,
				'agreementOnFile'        => !empty($draft['agreementOnFile']) ? 1 : 0,
				'authorizationOnFile'    => !empty($draft['authorizationOnFile']) ? 1 : 0,
				'notes'                  => isset($draft['notes']) ? trim($draft['notes']) : null,
				'updatedAt'              => current_time('mysql', true),
			);

			if ($existing) {
				$wpdb->update(
					$this->agency_profiles_table,
					$data,
					array('wordpressUserId' => $user_id)
				);
				$profile_id = $existing;
			} else {
				$data['id']              = wp_generate_uuid4();
				$data['wordpressUserId'] = $user_id;
				$data['createdAt']       = current_time('mysql', true);
				$wpdb->insert($this->agency_profiles_table, $data);
				$profile_id = $data['id'];
			}

			$saved = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->agency_profiles_table} WHERE id = %s LIMIT 1",
					$profile_id
				),
				ARRAY_A
			);

			return new WP_REST_Response(
				array(
					'ok'      => true,
					'profile' => array(
						'id'                     => $saved['id'],
						'source'                 => 'saved',
						'agencyName'             => $saved['agencyName'],
						'consultantName'         => $saved['consultantName'],
						'consultantEmail'        => $saved['consultantEmail'] ?? $current_user->user_email,
						'consultantPhone'        => $saved['consultantPhone'] ?? '',
						'defaultApplicationRoute' => $saved['defaultApplicationRoute'] ?? 'standard',
						'agreementOnFile'        => !empty($saved['agreementOnFile']),
						'authorizationOnFile'    => !empty($saved['authorizationOnFile']),
						'notes'                  => $saved['notes'] ?? '',
						'updatedAt'              => $saved['updatedAt'] ?? null,
					),
				),
				200
			);
		}

		private function agent_summary($agent, $profile = null) {
			return array(
				'id' => (int) $agent->ID,
				'username' => (string) $agent->user_login,
				'name' => (string) $agent->display_name,
				'email' => (string) $agent->user_email,
				'agencyName' => $profile && !empty($profile['agencyName']) ? (string) $profile['agencyName'] : (string) $agent->display_name,
				'consultantName' => $profile && !empty($profile['consultantName']) ? (string) $profile['consultantName'] : (string) $agent->display_name,
				'consultantEmail' => $profile && !empty($profile['consultantEmail']) ? (string) $profile['consultantEmail'] : (string) $agent->user_email,
				'consultantPhone' => $profile && !empty($profile['consultantPhone']) ? (string) $profile['consultantPhone'] : '',
				'defaultApplicationRoute' => $profile && isset($profile['defaultApplicationRoute']) && 'postgraduate' === $profile['defaultApplicationRoute'] ? 'postgraduate' : 'standard',
			);
		}

		public function rest_list_agents() {
			global $wpdb;
			$user = $this->current_session_user();
			if (!$this->is_admin_user($user)) {
				return $this->json_error_response('Administrator access required.', 403);
			}

			$agents = get_users(array('role__in' => array('mc_agent'), 'orderby' => 'display_name', 'order' => 'ASC'));
			$profiles_by_user = array();
			if (!empty($agents)) {
				$agent_ids = array_map('absint', wp_list_pluck($agents, 'ID'));
				$id_list = implode(',', $agent_ids);
				// IDs come only from WP_User objects and are normalized with absint above.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$profiles = $wpdb->get_results("SELECT * FROM {$this->agency_profiles_table} WHERE wordpressUserId IN ({$id_list})", ARRAY_A);
				foreach ($profiles as $profile) {
					$profiles_by_user[(int) $profile['wordpressUserId']] = $profile;
				}
			}

			return new WP_REST_Response(array(
				'ok' => true,
				'agents' => array_values(array_map(function ($agent) use ($profiles_by_user) {
					return $this->agent_summary($agent, isset($profiles_by_user[(int) $agent->ID]) ? $profiles_by_user[(int) $agent->ID] : null);
				}, $agents)),
			), 200);
		}

		public function rest_create_agent(WP_REST_Request $request) {
			global $wpdb;
			$user = $this->current_session_user();
			if (!$this->is_admin_user($user)) {
				return $this->json_error_response('Administrator access required.', 403);
			}

			$params = $request->get_json_params();
			$draft = isset($params['draft']) ? (array) $params['draft'] : array();
			$username = sanitize_user(isset($draft['username']) ? $draft['username'] : '', true);
			$email = sanitize_email(isset($draft['email']) ? $draft['email'] : '');
			$name = sanitize_text_field(isset($draft['name']) ? $draft['name'] : '');
			$agency_name = sanitize_text_field(isset($draft['agencyName']) ? $draft['agencyName'] : '');
			$consultant_name = sanitize_text_field(isset($draft['consultantName']) ? $draft['consultantName'] : '');
			$phone = sanitize_text_field(isset($draft['consultantPhone']) ? $draft['consultantPhone'] : '');
			$password = isset($draft['password']) ? (string) $draft['password'] : '';

			if (!$username || !validate_username($username)) return $this->json_error_response('A valid WordPress username is required.', 400);
			if (username_exists($username)) return $this->json_error_response('That username already exists.', 409);
			if (!$email || !is_email($email)) return $this->json_error_response('A valid email address is required.', 400);
			if (email_exists($email)) return $this->json_error_response('That email address already exists.', 409);
			if (!$name || !$agency_name || !$consultant_name) return $this->json_error_response('Display name, agency name, and consultant name are required.', 400);
			if (strlen($password) < 12) return $this->json_error_response('The temporary password must contain at least 12 characters.', 400);

			$suppress_new_user_email = function () { return false; };
			add_filter('wp_send_new_user_notification_to_user', $suppress_new_user_email);
			add_filter('wp_send_new_user_notification_to_admin', $suppress_new_user_email);
			try {
				$agent_id = wp_insert_user(array(
					'user_login' => $username,
					'user_email' => $email,
					'display_name' => $name,
					'user_pass' => $password,
					'role' => 'mc_agent',
				));
			} finally {
				remove_filter('wp_send_new_user_notification_to_user', $suppress_new_user_email);
				remove_filter('wp_send_new_user_notification_to_admin', $suppress_new_user_email);
			}
			if (is_wp_error($agent_id)) return $this->json_error_response($agent_id->get_error_message(), 400);

			$profile = array(
				'id' => wp_generate_uuid4(),
				'wordpressUserId' => (int) $agent_id,
				'wordpressUsername' => $username,
				'wordpressEmail' => $email,
				'agencyName' => $agency_name,
				'consultantName' => $consultant_name,
				'consultantEmail' => $email,
				'consultantPhone' => $phone,
				'defaultApplicationRoute' => 'standard',
				'agreementOnFile' => 0,
				'authorizationOnFile' => 0,
				'notes' => null,
				'createdAt' => current_time('mysql', true),
				'updatedAt' => current_time('mysql', true),
			);
			$wpdb->insert($this->agency_profiles_table, $profile);

			return new WP_REST_Response(array(
				'ok' => true,
				'agent' => $this->agent_summary(get_userdata($agent_id), $profile),
			), 201);
		}

		public function rest_get_application(WP_REST_Request $request) {
			try {
				$user = $this->current_session_user();
				$application = $this->get_admission_application_case($user, $request['application_id']);

				return new WP_REST_Response(
					array(
						'ok' => true,
						'application' => $application,
					),
					200
				);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_save_application(WP_REST_Request $request) {
			$params = $request->get_json_params();

			if (empty($params['draft']) || empty($params['mode'])) {
				return $this->json_error_response('Application details and action are required.', 400);
			}

			try {
				$user = $this->current_session_user();
				$saved = $this->save_admission_application(
					array(
						'applicationId' => isset($params['applicationId']) ? (string) $params['applicationId'] : null,
						'expectedUpdatedAt' => isset($params['expectedUpdatedAt']) ? (string) $params['expectedUpdatedAt'] : null,
						'isTestData' => array_key_exists('isTestData', $params) ? (bool) $params['isTestData'] : null,
						'mode' => (string) $params['mode'],
						'draft' => (array) $params['draft'],
						'user' => $user,
						'assignedAgentId' => isset($params['assignedAgentId']) ? absint($params['assignedAgentId']) : 0,
					)
				);

				return new WP_REST_Response(
					array(
						'ok' => true,
						'applicationId' => $saved['id'],
						'application' => $saved['application'],
						'caseRecord' => $saved['caseRecord'],
					),
					200
				);
			} catch (Exception $error) {
				$status = self::STALE_APPLICATION_ERROR === $error->getMessage() ? 409 : 400;
				return $this->json_error_response($error->getMessage(), $status);
			}
		}

		public function rest_update_workflow(WP_REST_Request $request) {
			$params = $request->get_json_params();

			if (empty($params['applicationId']) || empty($params['status'])) {
				return $this->json_error_response('Application id and status are required.', 400);
			}

			try {
				$user = $this->current_session_user();
				$saved = $this->update_admission_application_workflow(
					array(
						'applicationId' => (string) $params['applicationId'],
						'expectedUpdatedAt' => isset($params['expectedUpdatedAt']) ? (string) $params['expectedUpdatedAt'] : null,
						'status' => (string) $params['status'],
						'note' => isset($params['note']) ? (string) $params['note'] : null,
						'user' => $user,
					)
				);

				return new WP_REST_Response(
					array(
						'ok' => true,
						'applicationId' => $saved['id'],
						'application' => $saved['application'],
						'caseRecord' => $saved['caseRecord'],
						'stageChanged' => !empty($saved['stageChanged']),
						'staleCommandIgnored' => !empty($saved['staleCommandIgnored']),
					),
					200
				);
			} catch (Exception $error) {
				$status = self::STALE_APPLICATION_ERROR === $error->getMessage() ? 409 : 400;
				return $this->json_error_response($error->getMessage(), $status);
			}
		}

		public function rest_update_operations(WP_REST_Request $request) {
			$params = $request->get_json_params();

			if (empty($params['draft'])) {
				return $this->json_error_response('Operations payload is required.', 400);
			}

			try {
				$user = $this->current_session_user();
				$application = $this->update_admission_application_operations(
					array(
						'applicationId' => (string) $request['application_id'],
						'draft' => (array) $params['draft'],
						'expectedUpdatedAt' => isset($params['expectedUpdatedAt']) ? (string) $params['expectedUpdatedAt'] : null,
						'user' => $user,
					)
				);

				return new WP_REST_Response(
					array(
						'ok' => true,
						'application' => $application,
					),
					200
				);
			} catch (Exception $error) {
				$status = self::STALE_APPLICATION_ERROR === $error->getMessage() ? 409 : 400;
				return $this->json_error_response($error->getMessage(), $status);
			}
		}

		public function rest_upload_document(WP_REST_Request $request) {
			$file_params = $request->get_file_params();
			$document_type = $request->get_param('documentType');
			$expected_updated_at = $request->get_param('expectedUpdatedAt');
			$file = isset($file_params['file']) ? $file_params['file'] : null;

			if (empty($document_type) || empty($file) || empty($file['tmp_name'])) {
				return $this->json_error_response('Document type and file upload are required.', 400);
			}

			try {
				$user = $this->current_session_user();
				$application = $this->upload_admission_document(
					array(
						'applicationId' => (string) $request['application_id'],
						'documentType' => (string) $document_type,
						'fileName' => isset($file['name']) ? (string) $file['name'] : 'upload.bin',
						'mimeType' => !empty($file['type']) ? (string) $file['type'] : 'application/octet-stream',
						'filePath' => (string) $file['tmp_name'],
						'fileSize' => isset($file['size']) ? (int) $file['size'] : 0,
						'expectedUpdatedAt' => null !== $expected_updated_at ? (string) $expected_updated_at : null,
						'user' => $user,
					)
				);

				return new WP_REST_Response(
					array(
						'ok' => true,
						'application' => $application,
					),
					200
				);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), $this->document_mutation_error_status($error));
			}
		}

		public function rest_update_document_assessments(WP_REST_Request $request) {
			$params = $request->get_json_params();

			if (!is_array($params) || empty($params['assessments']) || !is_array($params['assessments'])) {
				return $this->json_error_response('Document assessments are required.', 400);
			}
			if (!isset($params['expectedUpdatedAt']) || '' === trim((string) $params['expectedUpdatedAt'])) {
				return $this->json_error_response('Application version is required.', 400);
			}

			try {
				$user = $this->current_session_user();
				$application = $this->update_admission_document_assessments(
					array(
						'applicationId' => (string) $request['application_id'],
						'assessments' => $params['assessments'],
						'expectedUpdatedAt' => (string) $params['expectedUpdatedAt'],
						'user' => $user,
					)
				);

				return new WP_REST_Response(
					array(
						'ok' => true,
						'application' => $application,
					),
					200
				);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), $this->document_mutation_error_status($error));
			}
		}

		public function rest_delete_document(WP_REST_Request $request) {
			$params = $request->get_json_params();

			if (!is_array($params) || !isset($params['documentType']) || '' === trim((string) $params['documentType'])) {
				return $this->json_error_response('Document type is required.', 400);
			}
			if (!isset($params['expectedUpdatedAt']) || '' === trim((string) $params['expectedUpdatedAt'])) {
				return $this->json_error_response('Application version is required.', 400);
			}

			try {
				$user = $this->current_session_user();
				$application = $this->delete_admission_document(
					array(
						'applicationId' => (string) $request['application_id'],
						'documentType' => (string) $params['documentType'],
						'expectedUpdatedAt' => (string) $params['expectedUpdatedAt'],
						'user' => $user,
					)
				);

				return new WP_REST_Response(
					array(
						'ok' => true,
						'application' => $application,
					),
					200
				);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), $this->document_mutation_error_status($error));
			}
		}

		public function rest_download_document_file(WP_REST_Request $request) {
			try {
				$user = $this->current_session_user();
				$document = $this->get_admission_document_download(
					array(
						'applicationId' => (string) $request['application_id'],
						'documentId' => (string) $request['document_id'],
						'user' => $user,
					)
				);

				$response = $this->download_document_file(
					$document['storageDriveId'],
					$document['storageItemId']
				);
				$body = wp_remote_retrieve_body($response);
				$content_type = wp_remote_retrieve_header($response, 'content-type');
				$file_name = !empty($document['originalName']) ? $document['originalName'] : ($document['label'] . '.bin');

				if (empty($body)) {
					throw new Exception('Document file not found.');
				}

				status_header(200);
				header('Content-Type: ' . (!empty($content_type) ? $content_type : 'application/octet-stream'));
				header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($file_name));
				header('Cache-Control: private, no-store');
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 404);
			}
		}

		public function rest_download_generated_letter_file(WP_REST_Request $request) {
			global $wpdb;
			try {
				$user = $this->current_session_user();
				$application_id = (string) $request['application_id'];
				$this->get_authorized_application_base($application_id, $user);
				$letter = $wpdb->get_row($wpdb->prepare(
					'SELECT fileName, outputFormat, renderedHtml FROM mc_generated_letters WHERE id = %s AND applicationId = %s LIMIT 1',
					(string) $request['letter_id'],
					$application_id
				), ARRAY_A);
				if (!$letter) {
					throw new Exception('Generated letter not found.');
				}
				$file_name = !empty($letter['fileName']) ? $letter['fileName'] : 'generated-letter';
				$body = (string) $letter['renderedHtml'];
				if ('pdf' === strtolower((string) $letter['outputFormat'])) {
					$body = base64_decode($body, true);
					if (false === $body || '' === $body) {
						throw new Exception('Generated letter file is empty or invalid.');
					}
					$content_type = 'application/pdf';
				} else {
					$content_type = 'text/html; charset=utf-8';
				}
				status_header(200);
				header('Content-Type: ' . $content_type);
				header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($file_name));
				header('Cache-Control: private, no-store');
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 404);
			}
		}

		private function posted_setting($key, $fallback = '') {
			if (!isset($_POST[$key])) {
				return $fallback;
			}

			return trim(wp_unslash((string) $_POST[$key]));
		}

		private function get_setting($key, $fallback = '') {
			global $wpdb;

			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT settingValue FROM {$this->settings_table} WHERE settingKey = %s LIMIT 1",
					$key
				)
			);

			if (null === $value || '' === $value) {
				return $fallback;
			}

			return (string) $value;
		}

		private function save_setting($key, $value) {
			global $wpdb;

			return false !== $wpdb->query(
				$wpdb->prepare(
					"
					INSERT INTO {$this->settings_table} (settingKey, settingValue, updatedAt)
					VALUES (%s, %s, CURRENT_TIMESTAMP(3))
					ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), updatedAt = CURRENT_TIMESTAMP(3)
					",
					$key,
					$value
				)
			);
		}

		private function current_session_user() {
			$user = wp_get_current_user();

			if (!$user || empty($user->ID)) {
				throw new Exception('Authentication required.');
			}

			return array(
				'id' => (int) $user->ID,
				'username' => (string) $user->user_login,
				'name' => (string) $user->display_name,
				'email' => (string) $user->user_email,
				'roles' => array_values(array_map('strval', (array) $user->roles)),
				'capabilityCount' => count((array) $user->allcaps),
				'avatarUrl' => get_avatar_url($user->ID, array('size' => 96)),
			);
		}

		private function is_admin_user($user) {
			return !empty($user['roles']) && in_array('administrator', $user['roles'], true);
		}

		private function can_access_agent_media($user) {
			if ($this->is_admin_user($user)) {
				return true;
			}

			$agent_roles = array('mc_agent', 'mc-agent', 'agency', 'agent', 'consultant', 'admissions-agent', 'subscriber');
			return !empty($user['roles']) && count(array_intersect($agent_roles, (array) $user['roles'])) > 0;
		}

		private function is_agent_user($user) {
			$agent_roles = array('mc_agent', 'mc-agent', 'agency', 'agent', 'consultant', 'admissions-agent', 'subscriber');
			return !empty($user['roles']) && count(array_intersect($agent_roles, (array) $user['roles'])) > 0;
		}

		private function is_external_agent_user($user) {
			return $this->is_agent_user($user) && !$this->can_view_all_applications($user);
		}

		private function can_edit_application_data($user) {
			if ($this->is_admin_user($user)) {
				return true;
			}

			$allowed_roles = array('mc_agent', 'mc-agent', 'agency', 'agent', 'consultant', 'admissions-agent', 'subscriber', 'migration-officer');
			return !empty($user['roles']) && count(array_intersect($allowed_roles, (array) $user['roles'])) > 0;
		}

		private function get_agent_media_records() {
			$decoded = json_decode($this->get_setting('agent_document_media', '[]'), true);
			if (!is_array($decoded)) {
				return array();
			}

			return array_values(array_filter($decoded, function ($record) {
				return is_array($record)
					&& !empty($record['id'])
					&& !empty($record['category'])
					&& !empty($record['storageDriveId'])
					&& !empty($record['storageItemId']);
			}));
		}

		private function public_agent_media_records($records) {
			return array_values(array_map(function ($record) {
				return array(
					'id' => (string) $record['id'],
					'category' => (string) $record['category'],
					'label' => isset($record['label']) ? (string) $record['label'] : '',
					'fileName' => isset($record['fileName']) ? (string) $record['fileName'] : '',
					'mimeType' => isset($record['mimeType']) ? (string) $record['mimeType'] : 'application/octet-stream',
					'fileSize' => isset($record['fileSize']) ? (int) $record['fileSize'] : 0,
					'uploadedAt' => isset($record['uploadedAt']) ? (string) $record['uploadedAt'] : '',
					'uploadedByName' => isset($record['uploadedByName']) ? (string) $record['uploadedByName'] : '',
					'url' => rest_url(self::API_NAMESPACE . '/agent-media/' . rawurlencode($record['id']) . '/file'),
				);
			}, $records));
		}

		private function find_agent_media_record($media_id) {
			foreach ($this->get_agent_media_records() as $record) {
				if (hash_equals((string) $record['id'], (string) $media_id)) {
					return $record;
				}
			}

			throw new Exception('Media file not found.');
		}

		private function validate_agent_media_file($category, $file_name, $mime_type, $file_size) {
			if ($file_size <= 0 || $file_size > 200 * 1024 * 1024) {
				throw new Exception('Files must be no larger than 200 MB.');
			}

			$extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
			if (in_array($category, array('admission-policy', 'about-mesoyios'), true) && ('pdf' !== $extension || 'application/pdf' !== $mime_type)) {
				throw new Exception('Admission Policy and About Mesoyios must be PDF files.');
			}

			$allowed_extensions = array('pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov');
			$allowed_mimes = array('application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm', 'video/quicktime');
			if ('marketing' === $category && (!in_array($extension, $allowed_extensions, true) || !in_array($mime_type, $allowed_mimes, true))) {
				throw new Exception('Marketing materials must be a PDF, image, or video file.');
			}

			$document_extensions = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt');
			$document_mimes = array(
				'application/pdf',
				'application/msword',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.ms-excel',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/vnd.ms-powerpoint',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'text/plain',
			);
			if ('other' === $category && (!in_array($extension, $document_extensions, true) || !in_array($mime_type, $document_mimes, true))) {
				throw new Exception('Other documents must be a PDF, Word, Excel, PowerPoint, or plain-text file.');
			}
			}

		// Internal office staff (not just WordPress administrators) can see and act
		// on every application, matching the desktop/Next.js visibility rules.
		// External agents (mc_agent) remain scoped to their own applications.
		private function can_view_all_applications($user) {
			if ($this->is_admin_user($user)) {
				return true;
			}

			if (empty($user['roles'])) {
				return false;
			}

			$staff_roles = array(
				'admissions-officer',
				'finance-officer',
				'migration-officer',
				'immigration-officer',
				'registrar',
			);

			return count(array_intersect($staff_roles, (array) $user['roles'])) > 0;
		}

		private function user_has_any_role($user, $roles) {
			return !empty($user['roles']) && count(array_intersect((array) $roles, (array) $user['roles'])) > 0;
		}

		private function can_manage_workflow_status($user, $status) {
			if (!$this->can_view_all_applications($user)) {
				return false;
			}

			if ($this->is_admin_user($user)) {
				return true;
			}

			$migration_roles = array('migration-officer', 'immigration-officer');

			switch ($this->canonical_status_key($status)) {
				case 'trashed':
					return false;
				case 'acceptance-issued':
				case 'migration-documents':
					return $this->user_has_any_role($user, array_merge(array('admissions-officer'), $migration_roles));
				case 'entry-permit-processing':
					return $this->user_has_any_role($user, $migration_roles);
				case 'arrival-immigration':
				case 'enrollment-complete':
					return $this->user_has_any_role($user, array_merge($migration_roles, array('registrar')));
				default:
					return $this->user_has_any_role($user, array('admissions-officer'));
			}
		}

		private function can_edit_migration_or_immigration_records($user) {
			return $this->is_admin_user($user)
				|| $this->user_has_any_role($user, array('migration-officer', 'immigration-officer'));
		}

		private function can_upload_admission_document($user, $document_type) {
			if (!isset($this->document_requirements[$document_type])) {
				return false;
			}

			if ($this->can_view_all_applications($user)) {
				return true;
			}

			if (!$this->is_agent_user($user)) {
				return false;
			}

			$intake_document_ids = array(
				'passport',
				'secondaryMarksheet',
				'higherSecondaryMarksheet',
				'englishCertificate',
				'studentSignature',
				'consultantSignature',
				'agencyAgreement',
				'authorizationCertificate',
				'bachelorDiploma',
				'bachelorTranscript',
				'bankTransactionConfirmation',
			);

			return in_array($document_type, $intake_document_ids, true);
		}

		private function can_assess_admission_documents($user) {
			return $this->is_admin_user($user)
				|| $this->user_has_any_role($user, array('admissions-officer'));
		}

		private function operations_field_groups() {
			return array(
				'common' => array(
					'workflowNote',
				),
				'admissions' => array(
					'reviewerDecision',
					'reviewSummary',
					'decisionDueDate',
					'offerIssuedDate',
					'offerExpiryDate',
					'offerConditionNote',
					'classesStartDate',
					'tuitionFeeFirstYear',
					'tuitionFeeFollowingYears',
					'termBalanceApplies',
				),
				'finance' => array(
					'paymentStatus',
					'paymentAmount',
					'paymentCurrency',
					'paymentReference',
					'paymentConfirmedDate',
					'financeNote',
					'commissionStatus',
					'commissionBaseAmount',
					'commissionAmount',
					'commissionCurrency',
					'commissionDueDate',
					'commissionPaidDate',
					'commissionNote',
					'refundStatus',
					'refundRequestedDate',
					'refundAmount',
					'refundCurrency',
					'refundPaidDate',
					'refundReason',
					'refundNote',
				),
				'permit' => array(
					'permitStatus',
					'permitReference',
					'permitSubmittedDate',
					'permitDecisionDate',
					'permitNote',
				),
				'arrival' => array(
					'arrivalStatus',
					'travelDate',
					'accommodationStatus',
					'enrollmentStatus',
					'orientationDate',
					'enrollmentNote',
					'lateArrivalReason',
				),
			);
		}

		private function allowed_operations_fields_for_user($user) {
			$groups = $this->operations_field_groups();

			if ($this->is_admin_user($user)) {
				return array_values(array_unique(array_merge($groups['common'], $groups['admissions'], $groups['finance'], $groups['permit'], $groups['arrival'])));
			}

			$allowed = $this->can_view_all_applications($user) ? $groups['common'] : array();
			if ($this->user_has_any_role($user, array('admissions-officer'))) {
				$allowed = array_merge($allowed, $groups['admissions']);
			}
			if ($this->user_has_any_role($user, array('finance-officer'))) {
				$allowed = array_merge($allowed, $groups['finance']);
			}
			if ($this->user_has_any_role($user, array('migration-officer', 'immigration-officer'))) {
				$allowed = array_merge($allowed, $groups['permit'], $groups['arrival']);
			}
			if ($this->user_has_any_role($user, array('registrar'))) {
				$allowed = array_merge($allowed, $groups['arrival']);
			}

			return array_values(array_unique($allowed));
		}

		private function assert_operations_patch_authorized($draft, $user) {
			if (!$this->can_view_all_applications($user)) {
				throw new Exception('Only internal admissions staff can update operational case details.');
			}

			$allowed = $this->allowed_operations_fields_for_user($user);
			$all_groups = $this->operations_field_groups();
			$supported = array_values(array_unique(array_merge($all_groups['common'], $all_groups['admissions'], $all_groups['finance'], $all_groups['permit'], $all_groups['arrival'])));
			$requested = array_keys((array) $draft);
			$unknown = array_values(array_diff($requested, $supported));

			if (!empty($unknown)) {
				throw new Exception('Unknown operational fields: ' . implode(', ', $unknown) . '.');
			}

			$forbidden = array_values(array_diff($requested, $allowed));
			if (!empty($forbidden)) {
				throw new Exception('You do not have permission to update operational fields: ' . implode(', ', $forbidden) . '.');
			}
		}

		private function trim_to_null($value) {
			if (null === $value) {
				return null;
			}

			$trimmed = trim((string) $value);

			return '' === $trimmed ? null : $trimmed;
		}

		private function trim_to_empty($value) {
			if (null === $value) {
				return '';
			}

			return trim((string) $value);
		}

		private function normalize_select_value($value, $allowed_values, $fallback) {
			return in_array($value, $allowed_values, true) ? $value : $fallback;
		}

		private function normalize_status($status) {
			if ('Draft' === $status) {
				return self::INITIAL_APPLICATION_STATUS;
			}

			return in_array($status, $this->pipeline_stages, true) ? $status : self::INITIAL_APPLICATION_STATUS;
		}

		private function canonical_status_key($status) {
			$legacy_statuses = array(
				'Draft' => 'profile-preparation',
				'Application in progress' => 'profile-preparation',
				'Under review' => 'review-pending',
				'Offer letter issued' => 'offer-issued',
				'Payment pending' => 'prepayment-pending',
				'Acceptance confirmed' => 'acceptance-issued',
				'Entry permit processing' => 'entry-permit-processing',
				'Ready to enroll' => 'enrollment-complete',
			);

			if (isset($legacy_statuses[$status])) {
				return $legacy_statuses[$status];
			}

			return in_array(
				$status,
				array(
					'profile-preparation',
					'review-pending',
					'offer-issued',
					'prepayment-pending',
					'acceptance-issued',
					'migration-documents',
					'entry-permit-processing',
					'arrival-immigration',
					'enrollment-complete',
					'rejected',
					'trashed',
				),
				true
			)
				? $status
				: 'profile-preparation';
		}

		private function workflow_status_rank($status) {
			$forward_stages = array(
				'profile-preparation',
				'review-pending',
				'offer-issued',
				'prepayment-pending',
				'acceptance-issued',
				'migration-documents',
				'entry-permit-processing',
				'arrival-immigration',
				'enrollment-complete',
			);
			$rank = array_search($this->canonical_status_key($status), $forward_stages, true);

			return false === $rank ? -1 : (int) $rank;
		}

		private function is_terminal_workflow_status($status) {
			return in_array($this->canonical_status_key($status), array('rejected', 'trashed'), true);
		}

		private function should_apply_stale_workflow_target($current_status, $target_status) {
			// A command based on an old case snapshot is never safe to replay.
			// Even an apparently exact-next transition can have prerequisites or
			// side effects that changed after the caller loaded the case.
			return false;
		}

		private function active_document_pack_for_status($status) {
			switch ($this->canonical_status_key($status)) {
				case 'acceptance-issued':
				case 'migration-documents':
				case 'entry-permit-processing':
					return 'migration';
				case 'arrival-immigration':
				case 'enrollment-complete':
					return 'immigration';
				default:
					return 'intake';
			}
		}

		private function workflow_note_for_status($status) {
			switch ($status) {
				case 'trashed':
					return 'Application moved to Trash by an administrator.';
				case 'review-pending':
					return 'Application restored from Trash and returned to pending assessment.';
				case 'profile-preparation':
				case 'Application in progress':
					return 'Application is being prepared. Complete the profile and document pack before review.';
				case 'Under review':
					return 'Application is queued for assessment and document verification.';
				case 'offer-issued':
				case 'Offer letter issued':
					return 'Offer issued. Send payment and acceptance instructions to the applicant.';
				case 'prepayment-pending':
				case 'Payment pending':
					return 'Waiting for tuition receipt or finance confirmation.';
				case 'acceptance-issued':
				case 'Acceptance confirmed':
					return 'Acceptance package has been issued. Hand the case over to migration document collection.';
				case 'migration-documents':
					return 'Migration documents are being recorded and checked before the entry permit submission.';
				case 'entry-permit-processing':
				case 'Entry permit processing':
					return 'Entry permit application is in progress. Monitor submission, payment, and decision updates.';
				case 'arrival-immigration':
					return 'Arrival and immigration follow-up is in progress.';
				case 'enrollment-complete':
				case 'Ready to enroll':
					return 'Admissions process complete. Hand over to enrollment and registrar.';
				case 'rejected':
					return 'Application rejected and closed after review.';
				default:
					return 'Application is being prepared. Complete the profile and document pack before review.';
			}
		}

		private function next_action_for_status($application, $ready_documents, $total_documents) {
			$missing_docs = max(0, $total_documents - $ready_documents);
			$status = $this->canonical_status_key($application['status']);

			switch ($status) {
				case 'profile-preparation':
				case 'Application in progress':
					return $missing_docs > 0
						? 'Complete the applicant profile and clear the missing document slots.'
						: 'Submit the case into review.';
				case 'review-pending':
				case 'Under review':
					return $missing_docs > 0
						? 'Request the outstanding documents before confirming the review outcome.'
						: ('pending' === $application['reviewerDecision']
							? 'Record the academic decision and issue the offer.'
							: 'Issue the offer letter and set the payment instructions.');
				case 'offer-issued':
				case 'Offer letter issued':
					return 'cleared' === $application['paymentStatus']
						? 'Move the cleared case into acceptance confirmation.'
						: 'Collect tuition payment evidence and signed acceptance.';
				case 'prepayment-pending':
				case 'Payment pending':
					return 'cleared' === $application['paymentStatus']
						? 'Confirm acceptance and begin permit processing.'
						: 'Wait for finance clearance or receipt verification.';
				case 'acceptance-issued':
				case 'Acceptance confirmed':
					return 'Collect and verify the migration document pack.';
				case 'migration-documents':
					return 'Prepare and submit the entry permit pack.';
				case 'entry-permit-processing':
				case 'Entry permit processing':
					return 'approved' === $application['permitStatus']
						? 'Capture travel and orientation details, then hand off to enrollment.'
						: 'Monitor permit status and keep the applicant updated.';
				case 'arrival-immigration':
					return 'Complete the after-arrival immigration and registrar tasks.';
				case 'enrollment-complete':
				case 'Ready to enroll':
					return 'enrolled' === $application['enrollmentStatus']
						? 'Case completed. Keep the record for audit and reporting.'
						: 'Finalize registrar handoff and orientation scheduling.';
				case 'rejected':
					return 'Case rejected. Keep the record for audit and reporting.';
				case 'trashed':
					return 'Application is in Trash and can be restored only by an administrator.';
				default:
					return 'Complete the applicant profile and clear the missing document slots.';
			}
		}

		private function get_lane_for_status($status) {
			switch ($status) {
				case 'profile-preparation':
				case 'review-pending':
				case 'offer-issued':
				case 'prepayment-pending':
				case 'Application in progress':
				case 'Under review':
				case 'Offer letter issued':
					return 'applications';
				case 'acceptance-issued':
				case 'migration-documents':
				case 'entry-permit-processing':
				case 'Payment pending':
				case 'Acceptance confirmed':
				case 'Entry permit processing':
					return 'migration';
				case 'arrival-immigration':
					return 'immigration';
				case 'enrollment-complete':
				case 'rejected':
				case 'trashed':
				case 'Ready to enroll':
					return 'closed';
				default:
					return 'applications';
			}
		}

		private function get_progress_for_status($status, $ready_documents) {
			$by_status = array(
				'profile-preparation' => 18,
				'review-pending' => 50,
				'offer-issued' => 66,
				'prepayment-pending' => 78,
				'acceptance-issued' => 86,
				'migration-documents' => 88,
				'entry-permit-processing' => 94,
				'arrival-immigration' => 96,
				'enrollment-complete' => 100,
				'Application in progress' => 18,
				'Under review' => 50,
				'Offer letter issued' => 66,
				'Payment pending' => 78,
				'Acceptance confirmed' => 86,
				'Entry permit processing' => 94,
				'Ready to enroll' => 100,
			);

			if (isset($by_status[$status])) {
				return $by_status[$status];
			}

			return max(12, (int) round(($ready_documents / 5) * 60));
		}

		private function programme_label_from_code($code) {
			return isset($this->programme_labels[$code]) ? $this->programme_labels[$code] : 'Programme not selected';
		}

		private function iso_to_mysql_datetime($value) {
			if (empty($value)) {
				return null;
			}

			try {
				$date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
			} catch (Exception $error) {
				throw new Exception('Invalid application version.');
			}

			return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
		}

		private function mysql_datetime_to_iso($value) {
			if (empty($value)) {
				return null;
			}

			try {
				$date = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
			} catch (Exception $error) {
				return (string) $value;
			}

			return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
		}

		private function list_admission_board_applications($user) {
			global $wpdb;

			$where_sql = '';
			$query_args = array();
			$commission_status_sql = "'not-applicable'";
			$refund_status_sql = "'none'";

			if (
				$this->commission_records_table ===
				$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->commission_records_table))
			) {
				$commission_status_sql = "COALESCE((SELECT commission.status FROM {$this->commission_records_table} commission WHERE commission.applicationId = app.id ORDER BY commission.updatedAt DESC, commission.createdAt DESC LIMIT 1), 'not-applicable')";
			}

			if (
				$this->refund_records_table ===
				$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->refund_records_table))
			) {
				$refund_status_sql = "COALESCE((SELECT refund.status FROM {$this->refund_records_table} refund WHERE refund.applicationId = app.id ORDER BY refund.updatedAt DESC, refund.createdAt DESC LIMIT 1), 'none')";
			}

			if (!$this->can_view_all_applications($user)) {
				$where_sql = 'WHERE app.wordpressUserId = %d';
				$query_args[] = (int) $user['id'];
			}

			// High safety cap only; the board shows every application (matches the desktop, which has no limit).
			$query_args[] = 5000;

			$sql = "
				SELECT
					app.*,
					migration.packSubmittedDate AS permitPackSubmittedDate,
					migration.paymentReference AS permitPaymentReference,
					migration.paymentDate AS permitPaymentDate,
					migration.decisionDate AS permitDecisionDate,
					migration.permitReference AS permitReference,
					{$commission_status_sql} AS commissionStatus,
					{$refund_status_sql} AS refundStatus,
					COALESCE(document_stats.documentCount, 0) AS documentCount,
					COALESCE(document_stats.readyDocumentCount, 0) AS readyDocumentCount,
					COALESCE(document_stats.readyIntakeDocumentCount, 0) AS readyIntakeDocumentCount,
					COALESCE(document_stats.readyMigrationDocumentCount, 0) AS readyMigrationDocumentCount,
					COALESCE(document_stats.readyImmigrationDocumentCount, 0) AS readyImmigrationDocumentCount
				FROM {$this->applications_table} app
				LEFT JOIN {$this->migration_cases_table} migration
					ON migration.applicationId = app.id
				LEFT JOIN (
					SELECT
						doc.applicationId,
						COUNT(*) AS documentCount,
						SUM(CASE WHEN doc.isReady = 1 THEN 1 ELSE 0 END) AS readyDocumentCount,
						SUM(CASE WHEN doc.isReady = 1 AND doc.type IN ('passport', 'secondaryMarksheet', 'higherSecondaryMarksheet', 'englishCertificate', 'studentSignature', 'consultantSignature', 'bachelorDiploma', 'bachelorTranscript') THEN 1 ELSE 0 END) AS readyIntakeDocumentCount,
						SUM(CASE WHEN doc.isReady = 1 AND doc.type IN ('migrationSupportingDocuments', 'entryPermitPaymentReceipt', 'entryPermitRecord', 'courierReceipt') THEN 1 ELSE 0 END) AS readyMigrationDocumentCount,
						SUM(CASE WHEN doc.isReady = 1 AND doc.type IN ('afterArrivalPaymentReceipt', 'enrollmentAgreement', 'bankStatement', 'rentalAgreement', 'medicalCertificate', 'xRayRecord', 'immigrationAppointmentRecord', 'immigrationPaymentReceipt', 'pinkCardRecord', 'insuranceCopy') THEN 1 ELSE 0 END) AS readyImmigrationDocumentCount
					FROM {$this->documents_table} doc
					GROUP BY doc.applicationId
				) document_stats ON document_stats.applicationId = app.id
				{$where_sql}
				ORDER BY app.updatedAt DESC
				LIMIT %d
			";

			$prepared = $wpdb->prepare($sql, $query_args);
			$rows = $wpdb->get_results($prepared, ARRAY_A);

			return array_map(array($this, 'to_board_application'), is_array($rows) ? $rows : array());
		}

		private function to_board_application($application) {
			$status = $this->normalize_status($application['status']);
			$intake_total = isset($application['applicationRoute']) && 'postgraduate' === $application['applicationRoute'] ? 8 : 6;
			$migration_total = 4;
			$immigration_total = 10;
			$intake_ready = isset($application['readyIntakeDocumentCount']) ? (int) $application['readyIntakeDocumentCount'] : 0;
			$migration_ready = isset($application['readyMigrationDocumentCount']) ? (int) $application['readyMigrationDocumentCount'] : 0;
			$immigration_ready = isset($application['readyImmigrationDocumentCount']) ? (int) $application['readyImmigrationDocumentCount'] : 0;
			$lane = $this->get_lane_for_status($status);
			$ready_documents = $intake_ready;
			$total_documents = $intake_total;

			if ('migration' === $lane) {
				$ready_documents = $migration_ready;
				$total_documents = $migration_total;
			} elseif ('immigration' === $lane) {
				$ready_documents = $immigration_ready;
				$total_documents = $immigration_total;
			}

			$missing_docs = max(0, $total_documents - $ready_documents);

			return array(
				'recordId' => $application['id'],
				'id' => $application['referenceCode'],
				'studentName' => $application['fullName'],
				'agentName' => $application['agencyName'],
				'programme' => $application['programmeLabel'],
				'semester' => trim($application['semester'] . ' ' . $application['year']),
				'stage' => $status,
				'stageKey' => isset($application['status']) ? (string) $application['status'] : $status,
				'permitStatus' => isset($application['permitStatus']) ? $application['permitStatus'] : 'not-started',
				'permitPackSubmittedDate' => !empty($application['permitPackSubmittedDate']) ? $application['permitPackSubmittedDate'] : null,
				'permitPaymentReference' => !empty($application['permitPaymentReference']) ? $application['permitPaymentReference'] : null,
				'permitPaymentDate' => !empty($application['permitPaymentDate']) ? $application['permitPaymentDate'] : null,
				'permitDecisionDate' => !empty($application['permitDecisionDate']) ? $application['permitDecisionDate'] : null,
				'permitReference' => !empty($application['permitReference']) ? $application['permitReference'] : null,
				'arrivalStatus' => isset($application['arrivalStatus']) ? $application['arrivalStatus'] : 'planning',
				'enrollmentStatus' => isset($application['enrollmentStatus']) ? $application['enrollmentStatus'] : 'pending',
				'lane' => $lane,
				'progress' => $this->get_progress_for_status($status, $ready_documents),
				'missingDocs' => $missing_docs,
				'readyDocuments' => $ready_documents,
				'totalIntakeDocuments' => $intake_total,
				'intakeMissingDocs' => max(0, $intake_total - $intake_ready),
				'intakeReadyDocuments' => $intake_ready,
				'totalMigrationDocuments' => $migration_total,
				'migrationMissingDocs' => max(0, $migration_total - $migration_ready),
				'migrationReadyDocuments' => $migration_ready,
				'totalImmigrationDocuments' => $immigration_total,
				'immigrationMissingDocs' => max(0, $immigration_total - $immigration_ready),
				'immigrationReadyDocuments' => $immigration_ready,
				'commissionStatus' => isset($application['commissionStatus']) ? $application['commissionStatus'] : 'not-applicable',
				'refundStatus' => isset($application['refundStatus']) ? $application['refundStatus'] : 'none',
				'nextAction' => $this->next_action_for_status($application, $ready_documents, $total_documents),
				'workflowNote' => !empty($application['workflowNote']) ? $application['workflowNote'] : null,
				'updatedByName' => !empty($application['lastUpdatedByName']) ? $application['lastUpdatedByName'] : null,
				'updatedAt' => $this->mysql_datetime_to_iso($application['updatedAt']),
				'isLive' => true,
			);
		}

		private function get_authorized_application_base($application_id, $user, $for_update = false) {
			global $wpdb;

			$lock_sql = $for_update ? ' FOR UPDATE' : '';
			$application = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, wordpressUserId, status, isTestData FROM {$this->applications_table} WHERE id = %s LIMIT 1{$lock_sql}",
					$application_id
				),
				ARRAY_A
			);

			if (!$application) {
				throw new Exception('Application not found.');
			}

			if (!$this->can_view_all_applications($user) && (int) $application['wordpressUserId'] !== (int) $user['id']) {
				throw new Exception('You are not allowed to access this application.');
			}

			return $application;
		}

		private function get_detailed_application_record($application_id) {
			global $wpdb;

			$application = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->applications_table} WHERE id = %s LIMIT 1",
					$application_id
				),
				ARRAY_A
			);

			if (!$application) {
				throw new Exception('Application not found.');
			}

			$documents = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->documents_table} WHERE applicationId = %s ORDER BY createdAt ASC",
					$application_id
				),
				ARRAY_A
			);

			$activities = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->activities_table} WHERE applicationId = %s ORDER BY createdAt DESC LIMIT 24",
					$application_id
				),
				ARRAY_A
			);

			$communications = array();
			if ($this->table_exists($this->communications_table)) {
				$communications = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$this->communications_table} WHERE applicationId = %s ORDER BY createdAt DESC LIMIT 24",
						$application_id
					),
					ARRAY_A
				);
			}

			$letter_drafts = array();
			if ($this->table_exists($this->letter_drafts_table)) {
				$letter_drafts = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$this->letter_drafts_table} WHERE applicationId = %s ORDER BY updatedAt DESC",
						$application_id
					),
					ARRAY_A
				);
			}

			$commissions = array();
			if ($this->table_exists($this->commission_records_table)) {
				$commissions = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$this->commission_records_table} WHERE applicationId = %s ORDER BY updatedAt DESC LIMIT 12",
						$application_id
					),
					ARRAY_A
				);
			}

			$refunds = array();
			if ($this->table_exists($this->refund_records_table)) {
				$refunds = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$this->refund_records_table} WHERE applicationId = %s ORDER BY updatedAt DESC LIMIT 12",
						$application_id
					),
					ARRAY_A
				);
			}

			$generated_letters = array();
			$letters_table = 'mc_generated_letters';
			$has_letters = $this->table_exists($letters_table);
			if ($has_letters) {
				$generated_letters = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, applicationId, templateId, templateLabel, templateVersion, stageKeySnapshot, fileName, outputFormat, generatedByName, createdAt FROM ' . $letters_table . ' WHERE applicationId = %s ORDER BY createdAt DESC LIMIT 24',
						$application_id
					),
					ARRAY_A
				);
			}

			// Case-detail sub-panels (payments / migration / immigration). These
			// reuse the same queries as the dedicated REST endpoints so the web
			// case detail renders them from this single fetch (it can't reach the
			// DB directly to load them itself).
			$payments = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, amount, currency, reference, swiftReference, confirmedDate, recordedByName, note, createdAt FROM {$this->payments_table} WHERE applicationId = %s ORDER BY createdAt DESC LIMIT 24",
					$application_id
				),
				ARRAY_A
			);
			$migration_case = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->migration_cases_table} WHERE applicationId = %s LIMIT 1",
					$application_id
				),
				ARRAY_A
			);
			$immigration_case = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->immigration_cases_table} WHERE applicationId = %s LIMIT 1",
					$application_id
				),
				ARRAY_A
			);

			$application['documents'] = is_array($documents) ? $documents : array();
			$application['activities'] = is_array($activities) ? $activities : array();
			$application['communications'] = is_array($communications) ? $communications : array();
			$application['generatedLetters'] = is_array($generated_letters) ? $generated_letters : array();
			$application['letterDrafts'] = is_array($letter_drafts) ? $letter_drafts : array();
			$application['commissionRecords'] = is_array($commissions) ? $commissions : array();
			$application['refundRecords'] = is_array($refunds) ? $refunds : array();
			$application['paymentTransactions'] = is_array($payments) ? $payments : array();
			$application['migrationCase'] = $migration_case ? $migration_case : null;
			$application['immigrationCase'] = $immigration_case ? $immigration_case : null;

			return $application;
		}

		private function map_document($document) {
			return array(
				'id' => $document['id'],
				'type' => $document['type'],
				'label' => $document['label'],
				'isReady' => !empty($document['isReady']),
				'assessmentStatus' => isset($document['assessmentStatus']) ? (string) $document['assessmentStatus'] : 'pending',
				'assessmentRemark' => !empty($document['assessmentRemark']) ? (string) $document['assessmentRemark'] : null,
				'assessedAt' => $this->mysql_datetime_to_iso(isset($document['assessedAt']) ? $document['assessedAt'] : null),
				'assessedByName' => !empty($document['assessedByName']) ? (string) $document['assessedByName'] : null,
				'uploadedUrl' => !empty($document['uploadedUrl']) ? $document['uploadedUrl'] : null,
				'originalName' => !empty($document['originalName']) ? $document['originalName'] : null,
				'mimeType' => !empty($document['mimeType']) ? $document['mimeType'] : null,
				'fileSizeBytes' => isset($document['fileSizeBytes']) ? (int) $document['fileSizeBytes'] : null,
				'uploadedAt' => !empty($document['uploadedAt']) ? $document['uploadedAt'] : null,
				'uploadedByName' => !empty($document['uploadedByName']) ? $document['uploadedByName'] : null,
			);
		}

		private function map_generated_letter($letter) {
			$application_id = (string) $letter['applicationId'];
			$letter_id = (string) $letter['id'];

			return array(
				'id' => $letter_id,
				'templateId' => (string) $letter['templateId'],
				'templateLabel' => (string) $letter['templateLabel'],
				'templateVersion' => (string) $letter['templateVersion'],
				'stageKey' => $this->canonical_status_key((string) $letter['stageKeySnapshot']),
				'fileName' => (string) $letter['fileName'],
				'outputFormat' => 'pdf' === strtolower((string) $letter['outputFormat']) ? 'pdf' : 'html',
				'outputUrl' => '/api/admissions/' . rawurlencode($application_id) . '/letters/' . rawurlencode($letter_id) . '/file',
				'generatedAt' => $this->mysql_datetime_to_iso($letter['createdAt']),
				'generatedByName' => (string) $letter['generatedByName'],
			);
		}

		private function map_communication($communication) {
			return array(
				'id' => (string) $communication['id'],
				'direction' => $this->normalize_select_value($communication['direction'], array('outbound', 'inbound', 'internal'), 'outbound'),
				'channel' => $this->normalize_select_value($communication['channel'], array('email', 'phone', 'whatsapp', 'meeting', 'portal'), 'email'),
				'subject' => !empty($communication['subject']) ? (string) $communication['subject'] : null,
				'detail' => isset($communication['detail']) ? (string) $communication['detail'] : '',
				'actorName' => isset($communication['actorName']) ? (string) $communication['actorName'] : '',
				'createdAt' => $this->mysql_datetime_to_iso($communication['createdAt']),
			);
		}

		private function map_letter_draft($draft) {
			return array(
				'id' => (string) $draft['id'],
				'templateId' => (string) $draft['templateId'],
				'templateLabel' => (string) $draft['templateLabel'],
				'body' => isset($draft['body']) ? (string) $draft['body'] : '',
				'status' => $this->normalize_select_value($draft['status'], array('draft', 'reviewed', 'approved'), 'draft'),
				'isPersisted' => true,
				'updatedAt' => $this->mysql_datetime_to_iso($draft['updatedAt']),
				'lastEditedByName' => !empty($draft['lastEditedByName']) ? (string) $draft['lastEditedByName'] : null,
				'reviewedByName' => !empty($draft['reviewedByName']) ? (string) $draft['reviewedByName'] : null,
				'reviewedAt' => $this->mysql_datetime_to_iso(isset($draft['reviewedAt']) ? $draft['reviewedAt'] : null),
				'approvedByName' => !empty($draft['approvedByName']) ? (string) $draft['approvedByName'] : null,
				'approvedAt' => $this->mysql_datetime_to_iso(isset($draft['approvedAt']) ? $draft['approvedAt'] : null),
			);
		}

		private function map_commission_record($record) {
			return array(
				'id' => (string) $record['id'],
				'status' => $this->normalize_select_value($record['status'], $this->commission_statuses, 'not-applicable'),
				'baseAmount' => !empty($record['baseAmount']) ? (string) $record['baseAmount'] : null,
				'amount' => !empty($record['amount']) ? (string) $record['amount'] : null,
				'currency' => !empty($record['currency']) ? (string) $record['currency'] : 'EUR',
				'dueDate' => !empty($record['dueDate']) ? (string) $record['dueDate'] : null,
				'paidDate' => !empty($record['paidDate']) ? (string) $record['paidDate'] : null,
				'note' => !empty($record['note']) ? (string) $record['note'] : null,
				'createdAt' => $this->mysql_datetime_to_iso($record['createdAt']),
				'updatedAt' => $this->mysql_datetime_to_iso($record['updatedAt']),
			);
		}

		private function map_refund_record($record) {
			return array(
				'id' => (string) $record['id'],
				'status' => $this->normalize_select_value($record['status'], $this->refund_statuses, 'none'),
				'requestedDate' => !empty($record['requestedDate']) ? (string) $record['requestedDate'] : null,
				'amount' => !empty($record['amount']) ? (string) $record['amount'] : null,
				'currency' => !empty($record['currency']) ? (string) $record['currency'] : 'EUR',
				'paidDate' => !empty($record['paidDate']) ? (string) $record['paidDate'] : null,
				'reason' => !empty($record['reason']) ? (string) $record['reason'] : null,
				'note' => !empty($record['note']) ? (string) $record['note'] : null,
				'createdAt' => $this->mysql_datetime_to_iso($record['createdAt']),
				'updatedAt' => $this->mysql_datetime_to_iso($record['updatedAt']),
			);
		}

		private function map_payment_transaction($payment) {
			return array(
				'id' => (string) $payment['id'],
				'amount' => (string) $payment['amount'],
				'currency' => (string) $payment['currency'],
				'reference' => !empty($payment['reference']) ? (string) $payment['reference'] : null,
				'swiftReference' => !empty($payment['swiftReference']) ? (string) $payment['swiftReference'] : null,
				'confirmedDate' => !empty($payment['confirmedDate']) ? (string) $payment['confirmedDate'] : null,
				'recordedByName' => (string) $payment['recordedByName'],
				'note' => !empty($payment['note']) ? (string) $payment['note'] : null,
				'createdAt' => $this->mysql_datetime_to_iso($payment['createdAt']),
			);
		}

		private function map_migration_case($migration_case) {
			if (!$migration_case) {
				return null;
			}

			return array(
				'id' => (string) $migration_case['id'],
				'packPreparedDate' => !empty($migration_case['packPreparedDate']) ? (string) $migration_case['packPreparedDate'] : null,
				'packSubmittedDate' => !empty($migration_case['packSubmittedDate']) ? (string) $migration_case['packSubmittedDate'] : null,
				'paymentReference' => !empty($migration_case['paymentReference']) ? (string) $migration_case['paymentReference'] : null,
				'paymentDate' => !empty($migration_case['paymentDate']) ? (string) $migration_case['paymentDate'] : null,
				'decisionDate' => !empty($migration_case['decisionDate']) ? (string) $migration_case['decisionDate'] : null,
				'permitReference' => !empty($migration_case['permitReference']) ? (string) $migration_case['permitReference'] : null,
				'note' => !empty($migration_case['note']) ? (string) $migration_case['note'] : null,
				'recordedByName' => (string) $migration_case['recordedByName'],
				'updatedAt' => $this->mysql_datetime_to_iso($migration_case['updatedAt']),
			);
		}

		private function map_immigration_case($immigration_case) {
			if (!$immigration_case) {
				return null;
			}

			return array(
				'id' => (string) $immigration_case['id'],
				'arrivalDate' => !empty($immigration_case['arrivalDate']) ? (string) $immigration_case['arrivalDate'] : null,
				'medicalCertDate' => !empty($immigration_case['medicalCertDate']) ? (string) $immigration_case['medicalCertDate'] : null,
				'xRayDate' => !empty($immigration_case['xRayDate']) ? (string) $immigration_case['xRayDate'] : null,
				'appointmentDate' => !empty($immigration_case['appointmentDate']) ? (string) $immigration_case['appointmentDate'] : null,
				'paymentReference' => !empty($immigration_case['paymentReference']) ? (string) $immigration_case['paymentReference'] : null,
				'insurancePolicyNumber' => !empty($immigration_case['insurancePolicyNumber']) ? (string) $immigration_case['insurancePolicyNumber'] : null,
				'insuranceExpirationDate' => !empty($immigration_case['insuranceExpirationDate']) ? (string) $immigration_case['insuranceExpirationDate'] : null,
				'pinkCardDate' => !empty($immigration_case['pinkCardDate']) ? (string) $immigration_case['pinkCardDate'] : null,
				'enrollmentAgreementDate' => !empty($immigration_case['enrollmentAgreementDate']) ? (string) $immigration_case['enrollmentAgreementDate'] : null,
				'note' => !empty($immigration_case['note']) ? (string) $immigration_case['note'] : null,
				'recordedByName' => (string) $immigration_case['recordedByName'],
				'updatedAt' => $this->mysql_datetime_to_iso($immigration_case['updatedAt']),
			);
		}

		private function to_admission_case($application) {
			$board = $this->to_board_application(
				array_merge(
					$application,
					array(
						'documentCount' => count($application['documents']),
						'readyDocumentCount' => count(
							array_filter(
								$application['documents'],
								function ($document) {
									return !empty($document['isReady']);
								}
							)
						),
					)
				)
			);

			$documents = array_map(array($this, 'map_document'), $application['documents']);
			$letters = array_map(
				array($this, 'map_generated_letter'),
				isset($application['generatedLetters']) && is_array($application['generatedLetters'])
					? $application['generatedLetters']
					: array()
			);
			$activity = array_map(
				array($this, 'map_activity_entry'),
				$application['activities']
			);
			$communications = array_map(
				array($this, 'map_communication'),
				isset($application['communications']) && is_array($application['communications'])
					? $application['communications']
					: array()
			);
			$letter_drafts = array_map(
				array($this, 'map_letter_draft'),
				isset($application['letterDrafts']) && is_array($application['letterDrafts'])
					? $application['letterDrafts']
					: array()
			);
			$commissions = array_map(
				array($this, 'map_commission_record'),
				isset($application['commissionRecords']) && is_array($application['commissionRecords'])
					? $application['commissionRecords']
					: array()
			);
			$refunds = array_map(
				array($this, 'map_refund_record'),
				isset($application['refundRecords']) && is_array($application['refundRecords'])
					? $application['refundRecords']
					: array()
			);
			$payment_transactions = array_map(
				array($this, 'map_payment_transaction'),
				isset($application['paymentTransactions']) && is_array($application['paymentTransactions'])
					? $application['paymentTransactions']
					: array()
			);
			$migration_case = $this->map_migration_case(
				isset($application['migrationCase']) ? $application['migrationCase'] : null
			);
			$immigration_case = $this->map_immigration_case(
				isset($application['immigrationCase']) ? $application['immigrationCase'] : null
			);
			$latest_payment = !empty($application['paymentTransactions'][0])
				? $application['paymentTransactions'][0]
				: null;
			$effective_payment_status = $application['paymentStatus'];
			if ($latest_payment && !in_array($effective_payment_status, array('receipt-received', 'cleared'), true)) {
				$effective_payment_status = 'cleared';
			}
			$effective_payment_amount = !empty($application['paymentAmount'])
				? $application['paymentAmount']
				: ($latest_payment && !empty($latest_payment['amount']) ? $latest_payment['amount'] : null);
			$effective_payment_currency = !empty($application['paymentCurrency'])
				? $application['paymentCurrency']
				: ($latest_payment && !empty($latest_payment['currency']) ? $latest_payment['currency'] : 'EUR');
			$effective_payment_reference = !empty($application['paymentReference'])
				? $application['paymentReference']
				: ($latest_payment && !empty($latest_payment['reference'])
					? $latest_payment['reference']
					: ($latest_payment && !empty($latest_payment['swiftReference']) ? $latest_payment['swiftReference'] : null));
			$effective_payment_confirmed_date = !empty($application['paymentConfirmedDate'])
				? $application['paymentConfirmedDate']
				: ($latest_payment && !empty($latest_payment['confirmedDate']) ? $latest_payment['confirmedDate'] : null);

			return array_merge(
				$board,
				array(
					// The case UI needs the raw canonical workflow key. Never
					// replace this with the human-readable stage label.
					'stageKey' => isset($application['status'])
						? $this->canonical_status_key((string) $application['status'])
						: 'profile-preparation',
					'activeDocumentPack' => $this->active_document_pack_for_status(
						isset($application['status']) ? (string) $application['status'] : 'profile-preparation'
					),
					'fullName' => $application['fullName'],
					'wordpressUsername' => !empty($application['wordpressUsername']) ? $application['wordpressUsername'] : null,
					'wordpressEmail' => !empty($application['wordpressEmail']) ? $application['wordpressEmail'] : null,
					'passportNumber' => $application['passportNumber'],
					'email' => $application['email'],
					'phone' => $application['phone'],
					'birthday' => $application['birthday'],
					'address' => $application['address'],
					'city' => $application['city'],
					'postalCode' => $application['postalCode'],
					'country' => $application['country'],
					'gender' => $application['gender'],
					'semesterCode' => $application['semester'],
					'year' => $application['year'],
					'applicationRoute' => !empty($application['applicationRoute']) ? $application['applicationRoute'] : 'standard',
					'programmeCode' => $application['programmeCode'],
					'consultantName' => $application['consultantName'],
					'consultantEmail' => !empty($application['consultantEmail']) ? $application['consultantEmail'] : null,
					'consultantPhone' => !empty($application['consultantPhone']) ? $application['consultantPhone'] : null,
					'submissionDate' => !empty($application['submissionDate']) ? $application['submissionDate'] : null,
					'tuitionAcknowledged' => !empty($application['tuitionAcknowledged']),
					'offerTermsAcknowledged' => !empty($application['offerTermsAcknowledged']),
					'gdprAcknowledged' => !empty($application['gdprAcknowledged']),
					'reviewSummary' => !empty($application['reviewSummary']) ? $application['reviewSummary'] : null,
					'reviewerDecision' => $application['reviewerDecision'],
					'decisionDueDate' => !empty($application['decisionDueDate']) ? $application['decisionDueDate'] : null,
					'offerIssuedDate' => !empty($application['offerIssuedDate']) ? $application['offerIssuedDate'] : null,
					'offerExpiryDate' => !empty($application['offerExpiryDate']) ? $application['offerExpiryDate'] : null,
					'offerConditionNote' => !empty($application['offerConditionNote']) ? $application['offerConditionNote'] : null,
					'classesStartDate' => !empty($application['classesStartDate']) ? $application['classesStartDate'] : null,
					'tuitionFeeFirstYear' => !empty($application['tuitionFeeFirstYear']) ? $application['tuitionFeeFirstYear'] : null,
					'tuitionFeeFollowingYears' => !empty($application['tuitionFeeFollowingYears']) ? $application['tuitionFeeFollowingYears'] : null,
					'termBalanceApplies' => !empty($application['termBalanceApplies']),
					'paymentStatus' => $effective_payment_status,
					'paymentAmount' => $effective_payment_amount,
					'paymentCurrency' => $effective_payment_currency,
					'paymentReference' => $effective_payment_reference,
					'paymentConfirmedDate' => $effective_payment_confirmed_date,
					'financeNote' => !empty($application['financeNote']) ? $application['financeNote'] : null,
					'permitStatus' => $application['permitStatus'],
					'permitReference' => !empty($application['permitReference']) ? $application['permitReference'] : null,
					'permitSubmittedDate' => !empty($application['permitSubmittedDate']) ? $application['permitSubmittedDate'] : null,
					'permitDecisionDate' => !empty($application['permitDecisionDate']) ? $application['permitDecisionDate'] : null,
					'permitNote' => !empty($application['permitNote']) ? $application['permitNote'] : null,
					'arrivalStatus' => $application['arrivalStatus'],
					'travelDate' => !empty($application['travelDate']) ? $application['travelDate'] : null,
					'accommodationStatus' => !empty($application['accommodationStatus']) ? $application['accommodationStatus'] : null,
					'enrollmentStatus' => $application['enrollmentStatus'],
					'orientationDate' => !empty($application['orientationDate']) ? $application['orientationDate'] : null,
					'enrollmentNote' => !empty($application['enrollmentNote']) ? $application['enrollmentNote'] : null,
					'lateArrivalReason' => !empty($application['lateArrivalReason']) ? $application['lateArrivalReason'] : null,
					'commissions' => $commissions,
					'refunds' => $refunds,
					'letterDrafts' => $letter_drafts,
					'letters' => $letters,
					'documents' => $documents,
					'activity' => $activity,
					'communications' => $communications,
					'paymentTransactions' => $payment_transactions,
					'migrationCase' => $migration_case,
					'immigrationCase' => $immigration_case,
					'createdAt' => $this->mysql_datetime_to_iso($application['createdAt']),
				)
			);
		}

		private function map_activity_entry($entry) {
			return array(
				'id' => $entry['id'],
				'kind' => self::NOTIFICATION_DOCUMENT_ACTIVITY_KIND === $entry['kind'] ? 'document' : $entry['kind'],
				'title' => $entry['title'],
				'detail' => !empty($entry['detail']) ? $entry['detail'] : null,
				'actorName' => $entry['actorName'],
				'createdAt' => $this->mysql_datetime_to_iso($entry['createdAt']),
			);
		}

		private function create_activity($application_id, $user, $kind, $title, $detail = null) {
			global $wpdb;

			return $wpdb->insert(
				$this->activities_table,
				array(
					'id' => wp_generate_uuid4(),
					'applicationId' => $application_id,
					'kind' => $kind,
					'title' => $title,
					'detail' => $this->trim_to_null($detail),
					'actorName' => $user['name'],
					'actorRole' => $this->is_external_agent_user($user) ? 'agent' : 'internal',
					'createdAt' => $this->current_notification_event_mysql_datetime(),
				),
				array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
			);
		}

		private function create_required_activity($application_id, $user, $kind, $title, $detail, $failure_message) {
			$written = $this->create_activity($application_id, $user, $kind, $title, $detail);
			if (false === $written || 0 === $written) {
				throw new Exception($failure_message);
			}

			return $written;
		}

		private function sync_document_checklist($application_id, $documents) {
			global $wpdb;

			foreach ($this->document_requirements as $document_id => $label) {
				$is_ready = !empty($documents[$document_id]) ? 1 : 0;

				$wpdb->query(
					$wpdb->prepare(
						"
						INSERT INTO {$this->documents_table}
							(id, applicationId, type, label, isReady, createdAt, updatedAt)
						VALUES
							(%s, %s, %s, %s, %d, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
						ON DUPLICATE KEY UPDATE
							label = VALUES(label),
							isReady = VALUES(isReady),
							updatedAt = CURRENT_TIMESTAMP(3)
						",
						wp_generate_uuid4(),
						$application_id,
						$document_id,
						$label,
						$is_ready
					)
				);
			}
		}

		private function normalize_operations_boolean($value) {
			if (is_bool($value)) {
				return $value ? 1 : 0;
			}

			return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true) ? 1 : 0;
		}

		private function normalize_operations_draft($draft, $fallback_status) {
			$draft = (array) $draft;
			$normalized = array();
			$select_fields = array(
				'reviewerDecision' => array($this->reviewer_decisions, 'pending'),
				'paymentStatus' => array($this->payment_statuses, 'awaiting-invoice'),
				'permitStatus' => array($this->permit_statuses, 'not-started'),
				'arrivalStatus' => array($this->arrival_statuses, 'planning'),
				'enrollmentStatus' => array($this->enrollment_statuses, 'pending'),
				'commissionStatus' => array($this->commission_statuses, 'not-applicable'),
				'refundStatus' => array($this->refund_statuses, 'none'),
			);

			foreach ($select_fields as $field => $definition) {
				if (array_key_exists($field, $draft)) {
					$normalized[$field] = $this->normalize_select_value($draft[$field], $definition[0], $definition[1]);
				}
			}

			$text_fields = array(
				'workflowNote', 'reviewSummary', 'decisionDueDate', 'offerIssuedDate', 'offerExpiryDate',
				'offerConditionNote', 'classesStartDate', 'tuitionFeeFirstYear', 'tuitionFeeFollowingYears',
				'paymentAmount', 'paymentCurrency', 'paymentReference', 'paymentConfirmedDate', 'financeNote',
				'permitReference', 'permitSubmittedDate', 'permitDecisionDate', 'permitNote', 'travelDate',
				'accommodationStatus', 'orientationDate', 'enrollmentNote', 'lateArrivalReason',
				'commissionBaseAmount', 'commissionAmount', 'commissionCurrency', 'commissionDueDate',
				'commissionPaidDate', 'commissionNote', 'refundRequestedDate', 'refundAmount',
				'refundCurrency', 'refundPaidDate', 'refundReason', 'refundNote',
			);

			foreach ($text_fields as $field) {
				if (array_key_exists($field, $draft)) {
					$normalized[$field] = $this->trim_to_null($draft[$field]);
				}
			}

			if (array_key_exists('workflowNote', $normalized) && null === $normalized['workflowNote']) {
				$normalized['workflowNote'] = $this->workflow_note_for_status($fallback_status);
			}
			if (array_key_exists('paymentCurrency', $normalized) && null === $normalized['paymentCurrency']) {
				$normalized['paymentCurrency'] = 'EUR';
			}
			if (array_key_exists('commissionCurrency', $normalized) && null === $normalized['commissionCurrency']) {
				$normalized['commissionCurrency'] = 'EUR';
			}
			if (array_key_exists('refundCurrency', $normalized) && null === $normalized['refundCurrency']) {
				$normalized['refundCurrency'] = 'EUR';
			}
			if (array_key_exists('termBalanceApplies', $draft)) {
				$normalized['termBalanceApplies'] = $this->normalize_operations_boolean($draft['termBalanceApplies']);
			}

			return $normalized;
		}

		private function table_exists($table) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
		}

		private function application_operations_column_map() {
			return array(
				'workflowNote' => array('workflowNote', '%s'),
				'reviewerDecision' => array('reviewerDecision', '%s'),
				'reviewSummary' => array('reviewSummary', '%s'),
				'decisionDueDate' => array('decisionDueDate', '%s'),
				'offerIssuedDate' => array('offerIssuedDate', '%s'),
				'offerExpiryDate' => array('offerExpiryDate', '%s'),
				'offerConditionNote' => array('offerConditionNote', '%s'),
				'classesStartDate' => array('classesStartDate', '%s'),
				'tuitionFeeFirstYear' => array('tuitionFeeFirstYear', '%s'),
				'tuitionFeeFollowingYears' => array('tuitionFeeFollowingYears', '%s'),
				'termBalanceApplies' => array('termBalanceApplies', '%d'),
				'paymentStatus' => array('paymentStatus', '%s'),
				'paymentAmount' => array('paymentAmount', '%s'),
				'paymentCurrency' => array('paymentCurrency', '%s'),
				'paymentReference' => array('paymentReference', '%s'),
				'paymentConfirmedDate' => array('paymentConfirmedDate', '%s'),
				'financeNote' => array('financeNote', '%s'),
				'permitStatus' => array('permitStatus', '%s'),
				'permitReference' => array('permitReference', '%s'),
				'permitSubmittedDate' => array('permitSubmittedDate', '%s'),
				'permitDecisionDate' => array('permitDecisionDate', '%s'),
				'permitNote' => array('permitNote', '%s'),
				'arrivalStatus' => array('arrivalStatus', '%s'),
				'travelDate' => array('travelDate', '%s'),
				'accommodationStatus' => array('accommodationStatus', '%s'),
				'enrollmentStatus' => array('enrollmentStatus', '%s'),
				'orientationDate' => array('orientationDate', '%s'),
				'enrollmentNote' => array('enrollmentNote', '%s'),
				'lateArrivalReason' => array('lateArrivalReason', '%s'),
			);
		}

		private function commission_operations_column_map() {
			return array(
				'commissionStatus' => 'status',
				'commissionBaseAmount' => 'baseAmount',
				'commissionAmount' => 'amount',
				'commissionCurrency' => 'currency',
				'commissionDueDate' => 'dueDate',
				'commissionPaidDate' => 'paidDate',
				'commissionNote' => 'note',
			);
		}

		private function refund_operations_column_map() {
			return array(
				'refundStatus' => 'status',
				'refundRequestedDate' => 'requestedDate',
				'refundAmount' => 'amount',
				'refundCurrency' => 'currency',
				'refundPaidDate' => 'paidDate',
				'refundReason' => 'reason',
				'refundNote' => 'note',
			);
		}

		private function upsert_partial_operations_record($table, $application_id, $normalized, $field_map, $defaults, $label) {
			global $wpdb;

			$patch = array();
			foreach ($field_map as $draft_field => $column) {
				if (array_key_exists($draft_field, $normalized)) {
					$patch[$column] = $normalized[$draft_field];
				}
			}

			if (empty($patch)) {
				return;
			}

			if (!$this->table_exists($table)) {
				throw new Exception('The ' . $label . ' table is not available.');
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_row(
				$wpdb->prepare("SELECT id FROM {$table} WHERE applicationId = %s ORDER BY updatedAt DESC LIMIT 1", $application_id),
				ARRAY_A
			);

			if ($existing) {
				$set_parts = array();
				$args = array();
				foreach ($patch as $column => $value) {
					if (null === $value) {
						$set_parts[] = $column . ' = NULL';
					} else {
						$set_parts[] = $column . ' = %s';
						$args[] = $value;
					}
				}
				$set_parts[] = 'updatedAt = CURRENT_TIMESTAMP(3)';
				$args[] = $existing['id'];
				// Column and table names come only from internal maps.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$written = $wpdb->query($wpdb->prepare("UPDATE {$table} SET " . implode(', ', $set_parts) . ' WHERE id = %s', $args));
				if (false === $written) {
					throw new Exception('Unable to update the ' . $label . ' record.');
				}
				return;
			}

			$data = array_merge(
				$defaults,
				$patch,
				array(
					'id' => wp_generate_uuid4(),
					'applicationId' => $application_id,
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$written = $wpdb->insert($table, $data);
			if (false === $written || 0 === $written) {
				throw new Exception('Unable to create the ' . $label . ' record.');
			}
		}

		private function get_admission_application_case($user, $application_id) {
			$this->get_authorized_application_base($application_id, $user);
			return $this->to_admission_case($this->get_detailed_application_record($application_id));
		}

		private function generate_reference_code() {
			global $wpdb;

			do {
				$reference_code =
					'MC-' .
					substr((string) round(microtime(true) * 1000), -5) .
					str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT);
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(1) FROM {$this->applications_table} WHERE referenceCode = %s",
						$reference_code
					)
				);
			} while ($exists > 0);

			return $reference_code;
		}

		private function save_admission_application($params) {
			global $wpdb;

			$user = $params['user'];
			$draft = $params['draft'];
			$mode = 'review' === $params['mode'] ? 'review' : 'draft';
			$status = 'review' === $mode ? 'Under review' : self::INITIAL_APPLICATION_STATUS;
			$expected_version = $this->iso_to_mysql_datetime($params['expectedUpdatedAt']);
			$record_id = !empty($params['applicationId']) ? $params['applicationId'] : null;
			$assigned_agent_id = !empty($params['assignedAgentId']) ? absint($params['assignedAgentId']) : 0;
			$requested_is_test_data = array_key_exists('isTestData', $params) && null !== $params['isTestData']
				? (bool) $params['isTestData']
				: null;
			$should_notify_review_submission = false;

			if (false === $wpdb->query('START TRANSACTION')) {
				throw new Exception('Unable to start the application save transaction.');
			}

			try {
				if ($record_id) {
					if (!$this->can_edit_application_data($user)) {
						throw new Exception('You do not have permission to edit application data.');
					}

					$existing_application = $this->get_authorized_application_base($record_id, $user);
					$is_preparation_status = 'profile-preparation' === $this->canonical_status_key((string) $existing_application['status']);
					$is_submitting_prepared_application = 'review' === $mode && ($this->is_agent_user($user) || $this->is_admin_user($user)) && $is_preparation_status;
					$should_notify_review_submission = $is_submitting_prepared_application && $this->is_external_agent_user($user);
					$next_is_test_data = $this->resolve_application_test_data(
						$draft,
						$user,
						$requested_is_test_data,
						!empty($existing_application['isTestData'])
					);

					if ('review' === $mode && !$is_submitting_prepared_application) {
						throw new Exception('Only an agent or administrator can submit an application that is still in preparation.');
					}

					$update_sql = "
						UPDATE {$this->applications_table}
						SET
							fullName = %s,
							passportNumber = %s,
							email = %s,
							phone = %s,
							birthday = %s,
							address = %s,
							city = %s,
							postalCode = %s,
							country = %s,
							gender = %s,
							semester = %s,
							year = %s,
							applicationRoute = %s,
							programmeCode = %s,
							programmeLabel = %s,
							agencyName = %s,
							consultantName = %s,
							consultantEmail = %s,
							consultantPhone = %s,
							submissionDate = %s,
							tuitionAcknowledged = %d,
							offerTermsAcknowledged = %d,
							gdprAcknowledged = %d,
							isTestData = %d,
							lastUpdatedByName = %s,
							updatedAt = CURRENT_TIMESTAMP(3)
						WHERE id = %s
					";

					$args = array(
						$this->trim_to_empty(isset($draft['fullName']) ? $draft['fullName'] : ''),
						$this->trim_to_empty(isset($draft['passportNumber']) ? $draft['passportNumber'] : ''),
						$this->trim_to_empty(isset($draft['email']) ? $draft['email'] : ''),
						$this->trim_to_empty(isset($draft['phone']) ? $draft['phone'] : ''),
						$this->trim_to_empty(isset($draft['birthday']) ? $draft['birthday'] : ''),
						$this->trim_to_empty(isset($draft['address']) ? $draft['address'] : ''),
						$this->trim_to_empty(isset($draft['city']) ? $draft['city'] : ''),
						$this->trim_to_empty(isset($draft['postalCode']) ? $draft['postalCode'] : ''),
						$this->trim_to_empty(isset($draft['country']) ? $draft['country'] : ''),
						$this->trim_to_empty(isset($draft['gender']) ? $draft['gender'] : ''),
						$this->trim_to_empty(isset($draft['semester']) ? $draft['semester'] : ''),
						$this->trim_to_empty(isset($draft['year']) ? $draft['year'] : ''),
						isset($draft['applicationRoute']) && 'postgraduate' === $draft['applicationRoute'] ? 'postgraduate' : 'standard',
						$this->trim_to_empty(isset($draft['programme']) ? $draft['programme'] : ''),
						$this->programme_label_from_code(isset($draft['programme']) ? $draft['programme'] : ''),
						$this->trim_to_empty(isset($draft['agencyName']) ? $draft['agencyName'] : ''),
						$this->trim_to_empty(isset($draft['consultantName']) ? $draft['consultantName'] : ''),
						$this->trim_to_null(isset($draft['consultantEmail']) ? $draft['consultantEmail'] : null),
						$this->trim_to_null(isset($draft['consultantPhone']) ? $draft['consultantPhone'] : null),
						$this->trim_to_null(isset($draft['submissionDate']) ? $draft['submissionDate'] : null),
						!empty($draft['tuitionAcknowledged']) ? 1 : 0,
						!empty($draft['offerTermsAcknowledged']) ? 1 : 0,
						!empty($draft['gdprAcknowledged']) ? 1 : 0,
						$next_is_test_data ? 1 : 0,
						$user['name'],
						$record_id,
					);

					if ($expected_version) {
						$update_sql .= " AND updatedAt = %s";
						$args[] = $expected_version;
					}

					$updated = $wpdb->query($wpdb->prepare($update_sql, $args));
					if (false === $updated) {
						throw new Exception('Unable to save the application details.');
					}
					if (0 === $updated && $expected_version) {
						throw new Exception(self::STALE_APPLICATION_ERROR);
					}

					if ($is_submitting_prepared_application) {
						$status_written = $wpdb->update(
							$this->applications_table,
							array(
								'status' => 'Under review',
								'workflowNote' => $this->workflow_note_for_status('Under review'),
							),
							array('id' => $record_id)
						);
						if (false === $status_written || 0 === $status_written) {
							throw new Exception('Unable to submit the application into the review queue.');
						}
					}

					$this->sync_document_checklist($record_id, isset($draft['documents']) ? (array) $draft['documents'] : array());

					$this->create_required_activity(
						$record_id,
						$user,
						$is_submitting_prepared_application ? 'workflow' : 'application',
						$is_submitting_prepared_application ? 'Application submitted for review' : 'Application details corrected',
						$is_submitting_prepared_application
							? 'The completed application was submitted into the admissions review queue.'
							: 'Application data was updated without changing the current workflow stage.',
						'Unable to record the application activity.'
					);
				} else {
					$owner = $user;
					if ($this->is_admin_user($user)) {
						if (!$assigned_agent_id) {
							throw new Exception('Select an agent before creating the application.');
						}
						$agent = get_userdata($assigned_agent_id);
						if (!$agent || !$this->is_agent_user(array('roles' => array_values((array) $agent->roles)))) {
							throw new Exception('The selected application owner is not a valid agent.');
						}
						$owner = array(
							'id' => (int) $agent->ID,
							'username' => (string) $agent->user_login,
							'name' => (string) $agent->display_name,
							'email' => (string) $agent->user_email,
							'roles' => array_values((array) $agent->roles),
						);
					} elseif ($assigned_agent_id) {
						throw new Exception('Only an administrator can assign an application to another agent.');
					}

					$record_id = wp_generate_uuid4();
					$should_notify_review_submission = 'review' === $mode && $this->is_external_agent_user($user);
					$next_is_test_data = $this->resolve_application_test_data(
						$draft,
						$user,
						$requested_is_test_data
					);

					$inserted = $wpdb->insert(
						$this->applications_table,
						array(
							'id' => $record_id,
							'referenceCode' => $this->generate_reference_code(),
							'wordpressUserId' => (int) $owner['id'],
							'wordpressUsername' => $owner['username'],
							'wordpressEmail' => $owner['email'],
							'fullName' => $this->trim_to_empty(isset($draft['fullName']) ? $draft['fullName'] : ''),
							'passportNumber' => $this->trim_to_empty(isset($draft['passportNumber']) ? $draft['passportNumber'] : ''),
							'email' => $this->trim_to_empty(isset($draft['email']) ? $draft['email'] : ''),
							'phone' => $this->trim_to_empty(isset($draft['phone']) ? $draft['phone'] : ''),
							'birthday' => $this->trim_to_empty(isset($draft['birthday']) ? $draft['birthday'] : ''),
							'address' => $this->trim_to_empty(isset($draft['address']) ? $draft['address'] : ''),
							'city' => $this->trim_to_empty(isset($draft['city']) ? $draft['city'] : ''),
							'postalCode' => $this->trim_to_empty(isset($draft['postalCode']) ? $draft['postalCode'] : ''),
							'country' => $this->trim_to_empty(isset($draft['country']) ? $draft['country'] : ''),
							'gender' => $this->trim_to_empty(isset($draft['gender']) ? $draft['gender'] : ''),
							'semester' => $this->trim_to_empty(isset($draft['semester']) ? $draft['semester'] : ''),
							'year' => $this->trim_to_empty(isset($draft['year']) ? $draft['year'] : ''),
							'applicationRoute' => isset($draft['applicationRoute']) && 'postgraduate' === $draft['applicationRoute'] ? 'postgraduate' : 'standard',
							'programmeCode' => $this->trim_to_empty(isset($draft['programme']) ? $draft['programme'] : ''),
							'programmeLabel' => $this->programme_label_from_code(isset($draft['programme']) ? $draft['programme'] : ''),
							'agencyName' => $this->trim_to_empty(isset($draft['agencyName']) ? $draft['agencyName'] : ''),
							'consultantName' => $this->trim_to_empty(isset($draft['consultantName']) ? $draft['consultantName'] : ''),
							'consultantEmail' => $this->trim_to_null(isset($draft['consultantEmail']) ? $draft['consultantEmail'] : null),
							'consultantPhone' => $this->trim_to_null(isset($draft['consultantPhone']) ? $draft['consultantPhone'] : null),
							'submissionDate' => $this->trim_to_null(isset($draft['submissionDate']) ? $draft['submissionDate'] : null),
							'tuitionAcknowledged' => !empty($draft['tuitionAcknowledged']) ? 1 : 0,
							'offerTermsAcknowledged' => !empty($draft['offerTermsAcknowledged']) ? 1 : 0,
							'gdprAcknowledged' => !empty($draft['gdprAcknowledged']) ? 1 : 0,
							'isTestData' => $next_is_test_data ? 1 : 0,
							'status' => $status,
							'workflowNote' => $this->workflow_note_for_status($status),
							'lastUpdatedByName' => $user['name'],
							'source' => self::DEFAULT_SOURCE,
							'createdAt' => current_time('mysql', true),
							'updatedAt' => current_time('mysql', true),
						)
					);
					if (false === $inserted || 0 === $inserted) {
						throw new Exception('Unable to create the application.');
					}

					$this->sync_document_checklist($record_id, isset($draft['documents']) ? (array) $draft['documents'] : array());

					$this->create_required_activity(
						$record_id,
						$user,
						'review' === $mode ? 'workflow' : 'application',
						'review' === $mode ? 'Application submitted for review' : 'Application created',
						'review' === $mode
							? 'A new application was submitted into the review queue from the intake form.'
							: 'A new admissions case was created from the desktop intake form.',
						'Unable to record the application activity.'
					);
				}

				if (false === $wpdb->query('COMMIT')) {
					throw new Exception('Unable to commit the application save transaction.');
				}
			} catch (Exception $error) {
				$wpdb->query('ROLLBACK');
				throw $error;
			}

			$application = $this->get_detailed_application_record($record_id);

			if ($this->should_send_review_submission_alert($application, $user, $should_notify_review_submission)) {
				$this->send_application_activity_alert($application, $user, 'new-application-submitted');
			}

			return array(
				'id' => $record_id,
				'application' => $this->to_board_application(
					array_merge(
						$application,
						array(
							'documentCount' => count($application['documents']),
							'readyDocumentCount' => count(
								array_filter(
									$application['documents'],
									function ($document) {
										return !empty($document['isReady']);
									}
								)
							),
						)
					)
				),
				'caseRecord' => $this->to_admission_case($application),
			);
		}

		private function update_admission_application_workflow($params) {
			global $wpdb;

			$user = $params['user'];
			$application_id = $params['applicationId'];
			$expected_version = $this->iso_to_mysql_datetime($params['expectedUpdatedAt']);
			$status = $this->normalize_status($params['status']);
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, wordpressUserId, status, workflowNote, updatedAt FROM {$this->applications_table} WHERE id = %s LIMIT 1",
					$application_id
				),
				ARRAY_A
			);

			if (!$existing) {
				throw new Exception('Application not found.');
			}

			if (('trashed' === $status || 'trashed' === $existing['status']) && !$this->is_admin_user($user)) {
				throw new Exception('Only an administrator can move applications to or restore them from Trash.');
			}

			if (!$this->can_view_all_applications($user) && (int) $existing['wordpressUserId'] !== (int) $user['id']) {
				throw new Exception('You are not allowed to update this application.');
			}

			if (!$this->can_manage_workflow_status($user, $status)) {
				throw new Exception('You do not have permission to move this application to the requested stage.');
			}

			$next_note = $this->trim_to_null($params['note']);
			$next_note = $next_note ? $next_note : $this->workflow_note_for_status($status);
			$update_sql = "
				UPDATE {$this->applications_table}
				SET
					status = %s,
					workflowNote = %s,
					lastUpdatedByName = %s,
					updatedAt = CURRENT_TIMESTAMP(3)
				WHERE id = %s
			";
			$args = array($status, $next_note, $user['name'], $application_id);
			$activity_source = $existing;
			$stale_command_ignored = false;
			$target_applied = false;

			if ($expected_version) {
				$update_sql .= " AND updatedAt = %s";
				$args[] = $expected_version;
			}

			$updated = $wpdb->query($wpdb->prepare($update_sql, $args));

			if (false === $updated) {
				throw new Exception('Unable to save the admissions workflow stage.');
			}

			if (0 === $updated && $expected_version) {
				$fresh = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, wordpressUserId, status, workflowNote, updatedAt FROM {$this->applications_table} WHERE id = %s LIMIT 1",
						$application_id
					),
					ARRAY_A
				);

				if (!$fresh) {
					throw new Exception('Application not found.');
				}

				$activity_source = $fresh;

				// Never replay a workflow command after its optimistic version has
				// gone stale. Return the authoritative case as a no-op and require a
				// deliberate action from the refreshed state.
				$stale_command_ignored = true;
			} else {
				$target_applied = $updated > 0;
			}

			$status_changed = $target_applied && (string) $activity_source['status'] !== (string) $status;
			$note_changed = $target_applied && (string) $activity_source['workflowNote'] !== (string) $next_note;

			if ($status_changed || $note_changed) {
				$this->create_activity(
					$application_id,
					$user,
					$status_changed ? 'workflow' : 'note',
					$status_changed ? "Stage moved to {$status}" : 'Workflow note updated',
					$next_note
				);
			}

			$application = $this->get_detailed_application_record($application_id);

			if (
				!$stale_command_ignored
				&& (!$application || !isset($application['status']) || $status !== (string) $application['status'])
			) {
				throw new Exception('The admissions workflow stage was not saved. Refresh and try again.');
			}

			return array(
				'id' => $application_id,
				'application' => $this->to_board_application(
					array_merge(
						$application,
						array(
							'documentCount' => count($application['documents']),
							'readyDocumentCount' => count(
								array_filter(
									$application['documents'],
									function ($document) {
										return !empty($document['isReady']);
									}
								)
							),
						)
					)
				),
				'caseRecord' => $this->to_admission_case($application),
				'stageChanged' => $status_changed,
				'staleCommandIgnored' => $stale_command_ignored,
			);
		}

		private function update_admission_application_operations($params) {
			global $wpdb;

			$user = $params['user'];
			$application_id = $params['applicationId'];
			$expected_version = $this->iso_to_mysql_datetime($params['expectedUpdatedAt']);
			$existing = $wpdb->get_row(
				$wpdb->prepare("SELECT * FROM {$this->applications_table} WHERE id = %s LIMIT 1", $application_id),
				ARRAY_A
			);

			if (!$existing) {
				throw new Exception('Application not found.');
			}

			$draft = isset($params['draft']) ? (array) $params['draft'] : array();
			if (empty($draft)) {
				throw new Exception('No operational fields were provided.');
			}

			$this->assert_operations_patch_authorized($draft, $user);
			$normalized = $this->normalize_operations_draft($draft, $this->normalize_status($existing['status']));

			$set_parts = array();
			$args = array();
			foreach ($this->application_operations_column_map() as $field => $definition) {
				if (!array_key_exists($field, $normalized)) {
					continue;
				}
				if (null === $normalized[$field]) {
					$set_parts[] = $definition[0] . ' = NULL';
				} else {
					$set_parts[] = $definition[0] . ' = ' . $definition[1];
					$args[] = $normalized[$field];
				}
			}

			$set_parts[] = 'lastUpdatedByName = %s';
			$args[] = $user['name'];
			$set_parts[] = 'updatedAt = CURRENT_TIMESTAMP(3)';
			$update_sql = "UPDATE {$this->applications_table} SET " . implode(', ', $set_parts) . ' WHERE id = %s';
			$args[] = $application_id;

			if ($expected_version) {
				$update_sql .= " AND updatedAt = %s";
				$args[] = $expected_version;
			}

			if (false === $wpdb->query('START TRANSACTION')) {
				throw new Exception('Unable to start the application update.');
			}

			try {
				$updated = $wpdb->query($wpdb->prepare($update_sql, $args));
				if (false === $updated) {
					throw new Exception('Unable to save the application details. Refresh and try again.');
				}
				if (0 === $updated && $expected_version) {
					throw new Exception(self::STALE_APPLICATION_ERROR);
				}

				$this->upsert_partial_operations_record(
					$this->commission_records_table,
					$application_id,
					$normalized,
					$this->commission_operations_column_map(),
					array('status' => 'not-applicable', 'currency' => 'EUR'),
					'commission'
				);
				$this->upsert_partial_operations_record(
					$this->refund_records_table,
					$application_id,
					$normalized,
					$this->refund_operations_column_map(),
					array('status' => 'none', 'currency' => 'EUR'),
					'refund'
				);

				if (false === $wpdb->query('COMMIT')) {
					throw new Exception('Unable to commit the application update.');
				}
			} catch (Exception $write_error) {
				$wpdb->query('ROLLBACK');
				error_log('MC Admissions operations update failed: ' . $write_error->getMessage());
				throw $write_error;
			}

			$detail_parts = array();
			if (array_key_exists('paymentStatus', $normalized) && $existing['paymentStatus'] !== $normalized['paymentStatus']) {
				$detail_parts[] = 'payment ' . $existing['paymentStatus'] . ' -> ' . $normalized['paymentStatus'];
			}
			if (array_key_exists('permitStatus', $normalized) && $existing['permitStatus'] !== $normalized['permitStatus']) {
				$detail_parts[] = 'permit ' . $existing['permitStatus'] . ' -> ' . $normalized['permitStatus'];
			}
			if (array_key_exists('enrollmentStatus', $normalized) && $existing['enrollmentStatus'] !== $normalized['enrollmentStatus']) {
				$detail_parts[] = 'enrollment ' . $existing['enrollmentStatus'] . ' -> ' . $normalized['enrollmentStatus'];
			}

			$this->create_activity(
				$application_id,
				$user,
				'operations',
				'Operational details updated',
				!empty($detail_parts) ? implode(', ', $detail_parts) : 'Review, offer, finance, permit, or enrollment fields were updated.'
			);

			return $this->to_admission_case($this->get_detailed_application_record($application_id));
		}

		private function upload_admission_document($params) {
			global $wpdb;

			$user = $params['user'];
			$application_id = $params['applicationId'];
			$document_type = $params['documentType'];
			$file_name = $params['fileName'];
			$mime_type = $params['mimeType'];
			$file_path = $params['filePath'];
			$file_size = (int) $params['fileSize'];
			$expected_updated_at = isset($params['expectedUpdatedAt']) ? trim((string) $params['expectedUpdatedAt']) : '';
			$expected_version = '' !== $expected_updated_at
				? $this->iso_to_mysql_datetime($expected_updated_at)
				: null;

			$this->get_authorized_application_base($application_id, $user);
			$should_notify_agent_document_upload = false;

			if (!isset($this->document_requirements[$document_type])) {
				throw new Exception('Unknown document type.');
			}

			if (!$this->can_upload_admission_document($user, $document_type)) {
				throw new Exception('You do not have permission to upload this document type.');
			}

			if ($file_size <= 0 || !file_exists($file_path)) {
				throw new Exception('Uploaded file is empty.');
			}

			if ($file_size > 15 * 1024 * 1024) {
				throw new Exception('Document uploads are limited to 15 MB.');
			}

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"
					SELECT id, storageDriveId, storageItemId
					FROM {$this->documents_table}
					WHERE applicationId = %s AND type = %s
					LIMIT 1
					",
					$application_id,
					$document_type
				),
				ARRAY_A
			);

			$stored_file = $this->store_document_file($application_id, $document_type, $file_name, $mime_type, $file_path);
			$document_id = !empty($existing['id']) ? $existing['id'] : wp_generate_uuid4();
			$uploaded_url = $this->build_document_file_url($application_id, $document_id);
			$uploaded_at = gmdate('c');

			if (false === $wpdb->query('START TRANSACTION')) {
				$this->delete_document_file($stored_file['storageDriveId'], $stored_file['storageItemId']);
				throw new Exception('Unable to start the document upload transaction.');
			}

			try {
				$application_at_upload = $this->get_authorized_application_base($application_id, $user, true);
				$should_notify_agent_document_upload = $this->should_send_post_submission_agent_document_alert(
					$application_at_upload,
					$user
				);

				$application_sql = "UPDATE {$this->applications_table} SET lastUpdatedByName = %s, updatedAt = CURRENT_TIMESTAMP(3) WHERE id = %s";
				$application_args = array($user['name'], $application_id);
				if ($expected_version) {
					$application_sql .= ' AND updatedAt = %s';
					$application_args[] = $expected_version;
				}

				$application_written = $wpdb->query($wpdb->prepare($application_sql, $application_args));
				if (false === $application_written) {
					throw new Exception('Unable to update the application before the document upload.');
				}
				if (0 === $application_written) {
					if ($expected_version) {
						throw new Exception(self::STALE_APPLICATION_ERROR);
					}
					throw new Exception('Unable to update the application before the document upload.');
				}

				$document_written = $wpdb->query(
					$wpdb->prepare(
						"
						INSERT INTO {$this->documents_table}
							(id, applicationId, type, label, isReady, assessmentStatus, assessmentRemark, assessedAt, assessedByName, uploadedUrl, storedFilename, storageProvider, storageDriveId, storageItemId, storagePath, storageWebUrl, originalName, mimeType, fileSizeBytes, uploadedAt, uploadedByName, createdAt, updatedAt)
						VALUES
							(%s, %s, %s, %s, 1, 'pending', NULL, NULL, NULL, %s, %s, 'microsoft-365', %s, %s, %s, %s, %s, %s, %d, %s, %s, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
						ON DUPLICATE KEY UPDATE
							label = VALUES(label),
							isReady = VALUES(isReady),
							assessmentStatus = 'pending',
							assessmentRemark = NULL,
							assessedAt = NULL,
							assessedByName = NULL,
							uploadedUrl = VALUES(uploadedUrl),
							storedFilename = VALUES(storedFilename),
							storageProvider = VALUES(storageProvider),
							storageDriveId = VALUES(storageDriveId),
							storageItemId = VALUES(storageItemId),
							storagePath = VALUES(storagePath),
							storageWebUrl = VALUES(storageWebUrl),
							originalName = VALUES(originalName),
							mimeType = VALUES(mimeType),
							fileSizeBytes = VALUES(fileSizeBytes),
							uploadedAt = VALUES(uploadedAt),
							uploadedByName = VALUES(uploadedByName),
							updatedAt = CURRENT_TIMESTAMP(3)
						",
						$document_id,
						$application_id,
						$document_type,
						$this->document_requirements[$document_type],
						$uploaded_url,
						$stored_file['storedFilename'],
						$stored_file['storageDriveId'],
						$stored_file['storageItemId'],
						$stored_file['storagePath'],
						$stored_file['storageWebUrl'],
						$file_name,
						$mime_type,
						$file_size,
						$uploaded_at,
						$user['name']
					)
				);
				if (false === $document_written) {
					throw new Exception('Unable to save the uploaded document record.');
				}

				$this->create_required_activity(
					$application_id,
					$user,
					$should_notify_agent_document_upload ? self::NOTIFICATION_DOCUMENT_ACTIVITY_KIND : 'document',
					$this->document_requirements[$document_type] . ' uploaded',
					$file_name . ' attached to the case file.',
					'Unable to record the document upload activity.'
				);

				$saved_document = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, isReady, storageDriveId, storageItemId FROM {$this->documents_table} WHERE applicationId = %s AND type = %s LIMIT 1",
						$application_id,
						$document_type
					),
					ARRAY_A
				);
				if (
					!$saved_document
					|| empty($saved_document['isReady'])
					|| (string) $saved_document['storageDriveId'] !== (string) $stored_file['storageDriveId']
					|| (string) $saved_document['storageItemId'] !== (string) $stored_file['storageItemId']
				) {
					throw new Exception('The uploaded document record could not be verified.');
				}

				if (false === $wpdb->query('COMMIT')) {
					throw new Exception('Unable to commit the document upload transaction.');
				}
			} catch (Exception $error) {
				$wpdb->query('ROLLBACK');
				$this->delete_document_file($stored_file['storageDriveId'], $stored_file['storageItemId']);
				throw $error;
			}

			if (!empty($existing['storageItemId'])) {
				$this->delete_document_file($existing['storageDriveId'], $existing['storageItemId']);
			}

			$application = $this->get_detailed_application_record($application_id);
			if (
				$should_notify_agent_document_upload
				&& $this->should_send_post_submission_agent_document_alert($application, $user)
			) {
				$this->send_application_activity_alert(
					$application,
					$user,
					'agent-document-uploaded',
					$document_type,
					$file_name
				);
			}

			return $this->to_admission_case($application);
		}

		private function normalize_document_assessment_drafts($assessments) {
			$normalized = array();
			$allowed_statuses = array('pending', 'approved', 'rejected');

			foreach ((array) $assessments as $assessment) {
				$assessment = (array) $assessment;
				$document_type = isset($assessment['documentType'])
					? sanitize_text_field((string) $assessment['documentType'])
					: '';

				if (!isset($this->document_requirements[$document_type])) {
					throw new Exception('Unknown document type.');
				}

				$assessment_status = isset($assessment['assessmentStatus'])
					? sanitize_text_field((string) $assessment['assessmentStatus'])
					: 'pending';
				$assessment_status = $this->normalize_select_value($assessment_status, $allowed_statuses, 'pending');
				$assessment_remark = isset($assessment['assessmentRemark'])
					? $this->trim_to_null(sanitize_textarea_field((string) $assessment['assessmentRemark']))
					: null;

				// The last draft for a requirement wins, matching the desktop Map normalization.
				$normalized[$document_type] = array(
					'documentType' => $document_type,
					'label' => $this->document_requirements[$document_type],
					'assessmentStatus' => $assessment_status,
					'assessmentRemark' => $assessment_remark,
				);
			}

			return array_values($normalized);
		}

		private function persist_document_assessments($application_id, $assessments, $existing_documents, $expected_version, $user) {
			global $wpdb;

			if (false === $wpdb->query('START TRANSACTION')) {
				throw new Exception('Unable to start the document assessment update.');
			}

			try {
				$application_written = $wpdb->query(
					$wpdb->prepare(
						"
						UPDATE {$this->applications_table}
						SET lastUpdatedByName = %s, updatedAt = CURRENT_TIMESTAMP(3)
						WHERE id = %s AND updatedAt = %s
						",
						$user['name'],
						$application_id,
						$expected_version
					)
				);
				if (false === $application_written) {
					throw new Exception('Unable to update the application document revision.');
				}
				if (0 === $application_written) {
					throw new Exception(self::STALE_APPLICATION_ERROR);
				}

				$assessed_at = current_time('mysql', true);
				$detail_parts = array();
				$status_labels = array(
					'approved' => 'valid',
					'rejected' => 'not valid',
					'pending' => 'pending review',
				);

				foreach ($assessments as $assessment) {
					$document_type = $assessment['documentType'];
					$existing = isset($existing_documents[$document_type]) ? $existing_documents[$document_type] : null;
					$document_id = !empty($existing['id']) ? (string) $existing['id'] : wp_generate_uuid4();
					$is_ready = !empty($existing['isReady']) ? 1 : 0;
					$is_pending = 'pending' === $assessment['assessmentStatus'];
					$document_written = $wpdb->query(
						$wpdb->prepare(
							"
							INSERT INTO {$this->documents_table}
								(id, applicationId, type, label, isReady, assessmentStatus, assessmentRemark, assessedAt, assessedByName, createdAt, updatedAt)
							VALUES
								(%s, %s, %s, %s, %d, %s, NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
							ON DUPLICATE KEY UPDATE
								label = VALUES(label),
								assessmentStatus = VALUES(assessmentStatus),
								assessmentRemark = VALUES(assessmentRemark),
								assessedAt = VALUES(assessedAt),
								assessedByName = VALUES(assessedByName),
								updatedAt = CURRENT_TIMESTAMP(3)
							",
							$document_id,
							$application_id,
							$document_type,
							$assessment['label'],
							$is_ready,
							$assessment['assessmentStatus'],
							null === $assessment['assessmentRemark'] ? '' : $assessment['assessmentRemark'],
							$is_pending ? '' : $assessed_at,
							$is_pending ? '' : $user['name']
						)
					);
					if (false === $document_written) {
						throw new Exception('Unable to save a document assessment.');
					}

					$detail = $assessment['label'] . ': ' . $status_labels[$assessment['assessmentStatus']];
					if (null !== $assessment['assessmentRemark']) {
						$detail .= ' (' . $assessment['assessmentRemark'] . ')';
					}
					$detail_parts[] = $detail;
				}

				$activity_written = $this->create_activity(
					$application_id,
					$user,
					'document',
					'Document assessments updated',
					implode('; ', $detail_parts)
				);
				if (false === $activity_written || 0 === $activity_written) {
					throw new Exception('Unable to record the document assessment activity.');
				}

				if (false === $wpdb->query('COMMIT')) {
					throw new Exception('Unable to commit the document assessment update.');
				}
			} catch (Exception $error) {
				$wpdb->query('ROLLBACK');
				throw $error;
			}
		}

		private function update_admission_document_assessments($params) {
			global $wpdb;

			$user = $params['user'];
			$application_id = $params['applicationId'];
			$expected_updated_at = isset($params['expectedUpdatedAt']) ? trim((string) $params['expectedUpdatedAt']) : '';
			if ('' === $expected_updated_at) {
				throw new Exception('Application version is required.');
			}
			$expected_version = $this->iso_to_mysql_datetime($expected_updated_at);

			$this->get_authorized_application_base($application_id, $user);
			if (!$this->can_assess_admission_documents($user)) {
				throw new Exception('You do not have permission to assess case documents.');
			}

			$assessments = $this->normalize_document_assessment_drafts($params['assessments']);
			if (empty($assessments)) {
				throw new Exception('At least one document assessment is required.');
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT id, type, label, isReady, assessmentStatus, assessmentRemark
					FROM {$this->documents_table}
					WHERE applicationId = %s
					",
					$application_id
				),
				ARRAY_A
			);
			if (!is_array($rows)) {
				throw new Exception('Unable to load the current document assessments.');
			}

			$existing_documents = array();
			foreach ($rows as $row) {
				$existing_documents[(string) $row['type']] = $row;
			}

			$changed = array_values(array_filter($assessments, function ($assessment) use ($existing_documents) {
				$existing = isset($existing_documents[$assessment['documentType']])
					? $existing_documents[$assessment['documentType']]
					: null;
				$existing_status = $this->normalize_select_value(
					isset($existing['assessmentStatus']) ? (string) $existing['assessmentStatus'] : 'pending',
					array('pending', 'approved', 'rejected'),
					'pending'
				);
				$existing_remark = $this->trim_to_null(isset($existing['assessmentRemark']) ? $existing['assessmentRemark'] : null);

				return $existing_status !== $assessment['assessmentStatus']
					|| $existing_remark !== $assessment['assessmentRemark'];
			}));

			if (!empty($changed)) {
				$this->persist_document_assessments(
					$application_id,
					$changed,
					$existing_documents,
					$expected_version,
					$user
				);
			}

			return $this->to_admission_case($this->get_detailed_application_record($application_id));
		}

		private function clear_document_record_and_touch_application($application_id, $document, $expected_version, $user) {
			global $wpdb;

			if (false === $wpdb->query('START TRANSACTION')) {
				throw new Exception('Unable to start the document removal.');
			}

			try {
				$application_written = $wpdb->query(
					$wpdb->prepare(
						"
						UPDATE {$this->applications_table}
						SET lastUpdatedByName = %s, updatedAt = CURRENT_TIMESTAMP(3)
						WHERE id = %s AND updatedAt = %s
						",
						$user['name'],
						$application_id,
						$expected_version
					)
				);
				if (false === $application_written) {
					throw new Exception('Unable to update the application document revision.');
				}
				if (0 === $application_written) {
					throw new Exception(self::STALE_APPLICATION_ERROR);
				}

				$document_written = $wpdb->query(
					$wpdb->prepare(
						"
						UPDATE {$this->documents_table}
						SET isReady = 0,
							assessmentStatus = 'pending',
							assessmentRemark = NULL,
							assessedAt = NULL,
							assessedByName = NULL,
							uploadedUrl = NULL,
							storedFilename = NULL,
							storageProvider = NULL,
							storageDriveId = NULL,
							storageItemId = NULL,
							storagePath = NULL,
							storageWebUrl = NULL,
							originalName = NULL,
							mimeType = NULL,
							fileSizeBytes = NULL,
							uploadedAt = NULL,
							uploadedByName = NULL,
							updatedAt = CURRENT_TIMESTAMP(3)
						WHERE id = %s
							AND applicationId = %s
							AND type = %s
							AND uploadedUrl IS NOT NULL
							AND COALESCE(storageDriveId, '') = %s
							AND COALESCE(storageItemId, '') = %s
						",
						$document['id'],
						$application_id,
						$document['type'],
						!empty($document['storageDriveId']) ? $document['storageDriveId'] : '',
						!empty($document['storageItemId']) ? $document['storageItemId'] : ''
					)
				);
				if (false === $document_written || 0 === $document_written) {
					throw new Exception('No uploaded file was found for this document requirement.');
				}

				$activity_written = $this->create_activity(
					$application_id,
					$user,
					'document',
					$document['label'] . ' removed',
					(!empty($document['originalName']) ? $document['originalName'] : $document['label']) . ' removed from the case file.'
				);
				if (false === $activity_written || 0 === $activity_written) {
					throw new Exception('Unable to record the document removal activity.');
				}

				if (false === $wpdb->query('COMMIT')) {
					throw new Exception('Unable to commit the document removal.');
				}
			} catch (Exception $error) {
				$wpdb->query('ROLLBACK');
				throw $error;
			}
		}

		private function delete_admission_document($params) {
			global $wpdb;

			$user = $params['user'];
			$application_id = $params['applicationId'];
			$document_type = $params['documentType'];
			$expected_updated_at = isset($params['expectedUpdatedAt']) ? trim((string) $params['expectedUpdatedAt']) : '';
			if ('' === $expected_updated_at) {
				throw new Exception('Application version is required.');
			}
			$expected_version = $this->iso_to_mysql_datetime($expected_updated_at);

			$this->get_authorized_application_base($application_id, $user);
			if (!$this->can_upload_admission_document($user, $document_type)) {
				throw new Exception('You do not have permission to remove this document.');
			}

			$document = $wpdb->get_row(
				$wpdb->prepare(
					"
					SELECT id, type, label, originalName, storageProvider, storageDriveId, storageItemId, uploadedUrl
					FROM {$this->documents_table}
					WHERE applicationId = %s AND type = %s
					LIMIT 1
					",
					$application_id,
					$document_type
				),
				ARRAY_A
			);
			if (!$document || empty($document['uploadedUrl'])) {
				throw new Exception('No uploaded file was found for this document requirement.');
			}

			$this->clear_document_record_and_touch_application(
				$application_id,
				$document,
				$expected_version,
				$user
			);

			// Never remove the remote object until the database transaction commits.
			// Exact drive/item IDs prevent a stale path from targeting a replacement file.
			if (!empty($document['storageItemId'])) {
				$this->delete_document_file($document['storageDriveId'], $document['storageItemId']);
			}

			return $this->to_admission_case($this->get_detailed_application_record($application_id));
		}

		private function get_admission_document_download($params) {
			global $wpdb;

			$this->get_authorized_application_base($params['applicationId'], $params['user']);

			$document = $wpdb->get_row(
				$wpdb->prepare(
					"
					SELECT label, originalName, mimeType, storageDriveId, storageItemId
					FROM {$this->documents_table}
					WHERE id = %s AND applicationId = %s
					LIMIT 1
					",
					$params['documentId'],
					$params['applicationId']
				),
				ARRAY_A
			);

			if (!$document || empty($document['storageItemId'])) {
				throw new Exception('Document file not found.');
			}

			return $document;
		}

		private function build_document_file_url($application_id, $document_id) {
			return rest_url(
				sprintf(
					'%s/applications/%s/documents/%s/file',
					self::API_NAMESPACE,
					rawurlencode($application_id),
					rawurlencode($document_id)
				)
			);
		}

		private function store_document_file($application_id, $document_type, $original_name, $mime_type, $file_path) {
			$config = $this->get_m365_config();
			$token = $this->get_m365_access_token($config);
			$stored_filename = $this->build_stored_filename($document_type, $original_name);
			$relative_path = trim($config['documentRoot'], '/') . '/' . rawurlencode($application_id) . '/' . rawurlencode($stored_filename);
			$url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($config['driveId']) . '/root:/' . $relative_path . ':/content';
			$body = file_get_contents($file_path);

			if (false === $body) {
				throw new Exception('Unable to read the uploaded file.');
			}

			$response = wp_remote_request(
				$url,
				array(
					'method' => 'PUT',
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type' => $mime_type,
					),
					'body' => $body,
					'timeout' => 60,
				)
			);

			if (is_wp_error($response)) {
				throw new Exception('Unable to upload the document to Microsoft 365.');
			}

			$status = (int) wp_remote_retrieve_response_code($response);
			$payload = json_decode(wp_remote_retrieve_body($response), true);

			if ($status < 200 || $status >= 300 || empty($payload['id'])) {
				throw new Exception('Microsoft 365 rejected the document upload.');
			}

			$parent_path = isset($payload['parentReference']['path']) ? (string) $payload['parentReference']['path'] : null;

			return array(
				'storedFilename' => isset($payload['name']) ? (string) $payload['name'] : $stored_filename,
				'storageDriveId' => $config['driveId'],
				'storageItemId' => (string) $payload['id'],
				'storagePath' => $parent_path ? ($parent_path . '/' . (isset($payload['name']) ? $payload['name'] : $stored_filename)) : null,
				'storageWebUrl' => isset($payload['webUrl']) ? (string) $payload['webUrl'] : null,
			);
		}

		private function delete_document_file($drive_id, $item_id) {
			if (empty($drive_id) || empty($item_id)) {
				return true;
			}

			try {
				$config = $this->get_m365_config();
				$token = $this->get_m365_access_token($config);

				$response = wp_remote_request(
					'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($drive_id) . '/items/' . rawurlencode($item_id),
					array(
						'method' => 'DELETE',
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
						),
						'timeout' => 30,
					)
				);

				if (is_wp_error($response)) {
					return false;
				}

				$status = (int) wp_remote_retrieve_response_code($response);
				return (200 <= $status && 300 > $status) || 404 === $status;
			} catch (Exception $error) {
				// Storage cleanup is best-effort after a committed DB mutation.
				return false;
			}
		}

		private function download_document_file($drive_id, $item_id) {
			$config = $this->get_m365_config();
			$token = $this->get_m365_access_token($config);

			$response = wp_remote_get(
				'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($drive_id) . '/items/' . rawurlencode($item_id) . '/content',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
					),
					'timeout' => 60,
				)
			);

			if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
				throw new Exception('Unable to open the document file.');
			}

			return $response;
		}

		private function get_m365_config() {
			$config = array(
				'tenantId' => $this->get_setting('m365_tenant_id'),
				'clientId' => $this->get_setting('m365_client_id'),
				'clientSecret' => $this->get_setting('m365_client_secret'),
				'driveId' => $this->get_setting('m365_drive_id'),
				'documentRoot' => $this->get_setting('m365_document_root', 'Admissions'),
			);

			foreach ($config as $value) {
				if ('' === trim((string) $value)) {
					throw new Exception('Microsoft 365 storage is not configured in WordPress yet.');
				}
			}

			return $config;
		}

		private function get_m365_access_token($config) {
			$cache_key = 'mc_admissions_m365_token_' . md5($config['tenantId'] . '|' . $config['clientId']);
			$cached = get_transient($cache_key);

			if (is_array($cached) && !empty($cached['access_token'])) {
				return $cached['access_token'];
			}

			$response = wp_remote_post(
				'https://login.microsoftonline.com/' . rawurlencode($config['tenantId']) . '/oauth2/v2.0/token',
				array(
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
					),
					'body' => array(
						'client_id' => $config['clientId'],
						'client_secret' => $config['clientSecret'],
						'scope' => 'https://graph.microsoft.com/.default',
						'grant_type' => 'client_credentials',
					),
					'timeout' => 30,
				)
			);

			if (is_wp_error($response)) {
				throw new Exception('Unable to authenticate with Microsoft 365.');
			}

			$payload = json_decode(wp_remote_retrieve_body($response), true);
			$status = (int) wp_remote_retrieve_response_code($response);

			if ($status < 200 || $status >= 300 || empty($payload['access_token'])) {
				throw new Exception('Microsoft 365 authentication failed.');
			}

			$expires_in = !empty($payload['expires_in']) ? max(60, ((int) $payload['expires_in']) - 60) : 3000;

			set_transient(
				$cache_key,
				array(
					'access_token' => $payload['access_token'],
				),
				$expires_in
			);

			return $payload['access_token'];
		}

		private function build_stored_filename($document_type, $original_name) {
			$extension = pathinfo($original_name, PATHINFO_EXTENSION);
			$base = sanitize_file_name(pathinfo($original_name, PATHINFO_FILENAME));

			if ('' === $base) {
				$base = $document_type;
			}

			return $document_type . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . ($extension ? '.' . strtolower($extension) : '');
		}

		private function is_allowed_origin($origin) {
			$allowed = apply_filters(
				'mc_admissions_allowed_origins',
				array(
					'#^https?://127\.0\.0\.1(?::\d+)?$#i',
					'#^https?://localhost(?::\d+)?$#i',
				)
			);

			foreach ((array) $allowed as $pattern) {
				if (preg_match($pattern, $origin)) {
					return true;
				}
			}

			return false;
		}

		public function rest_list_payments(WP_REST_Request $request) {
			global $wpdb;
			try {
				$user = $this->current_session_user();
				$application_id = sanitize_text_field($request['application_id']);
				$this->get_authorized_application_base($application_id, $user);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results($wpdb->prepare("SELECT id, amount, currency, reference, swiftReference, confirmedDate, recordedByName, note, createdAt FROM {$this->payments_table} WHERE applicationId = %s ORDER BY createdAt DESC LIMIT 24", $application_id), ARRAY_A);
				return new WP_REST_Response(array('ok' => true, 'transactions' => $rows ?: array()), 200);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_create_payment(WP_REST_Request $request) {
			global $wpdb;
			$params = $request->get_json_params();
			$draft = isset($params['draft']) ? (array) $params['draft'] : array();
			if (empty($draft['amount'])) {
				return $this->json_error_response('Payment amount is required.', 400);
			}
			try {
				$user = $this->current_session_user();
				if (!$this->is_admin_user($user) && !in_array('finance-officer', $user['roles'], true)) {
					throw new Exception('You do not have permission to record payment transactions.');
				}
				$application_id = sanitize_text_field($request['application_id']);
				$this->get_authorized_application_base($application_id, $user);
				$id = wp_generate_uuid4();
				$amount = sanitize_text_field($draft['amount']);
				$currency = sanitize_text_field($draft['currency'] ?? 'EUR');
				$reference = isset($draft['reference']) && '' !== trim((string) $draft['reference'])
					? sanitize_text_field($draft['reference'])
					: (isset($draft['swiftReference']) ? sanitize_text_field($draft['swiftReference']) : null);
				$confirmed_date = isset($draft['confirmedDate']) && '' !== trim((string) $draft['confirmedDate'])
					? sanitize_text_field($draft['confirmedDate'])
					: wp_date('Y-m-d');
				if (false === $wpdb->query('START TRANSACTION')) {
					throw new Exception('Unable to start the payment transaction.');
				}

				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$payment_written = $wpdb->insert($this->payments_table, array(
						'id' => $id, 'applicationId' => $application_id,
						'amount' => $amount,
						'currency' => $currency,
						'reference' => isset($draft['reference']) ? sanitize_text_field($draft['reference']) : null,
						'swiftReference' => isset($draft['swiftReference']) ? sanitize_text_field($draft['swiftReference']) : null,
						'confirmedDate' => $confirmed_date,
						'recordedByName' => $user['name'],
						'note' => isset($draft['note']) ? sanitize_textarea_field($draft['note']) : null,
					));
					if (false === $payment_written || 0 === $payment_written) {
						throw new Exception('Unable to save the payment transaction.');
					}

					// Keep the application-level finance state in sync because acceptance-letter
					// availability is evaluated from these fields for every staff role.
					$application_written = $wpdb->query(
						$wpdb->prepare(
							"
							UPDATE {$this->applications_table}
							SET paymentStatus = 'cleared', paymentAmount = %s, paymentCurrency = %s,
								paymentReference = %s, paymentConfirmedDate = %s,
								lastUpdatedByName = %s, updatedAt = CURRENT_TIMESTAMP(3)
							WHERE id = %s
							",
							$amount,
							$currency,
							$reference,
							$confirmed_date,
							$user['name'],
							$application_id
						)
					);
					if (false === $application_written || 0 === $application_written) {
						throw new Exception('Unable to update the application payment status.');
					}

					$saved_payment_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$this->payments_table} WHERE id = %s AND applicationId = %s LIMIT 1",
							$id,
							$application_id
						)
					);
					if ((string) $saved_payment_id !== (string) $id) {
						throw new Exception('The payment transaction could not be verified.');
					}

					if (false === $wpdb->query('COMMIT')) {
						throw new Exception('Unable to commit the payment transaction.');
					}
				} catch (Exception $write_error) {
					$wpdb->query('ROLLBACK');
					throw $write_error;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results($wpdb->prepare("SELECT id, amount, currency, reference, swiftReference, confirmedDate, recordedByName, note, createdAt FROM {$this->payments_table} WHERE applicationId = %s ORDER BY createdAt DESC LIMIT 24", $application_id), ARRAY_A);
				$application = $this->to_admission_case($this->get_detailed_application_record($application_id));
				return new WP_REST_Response(array(
					'ok' => true,
					'transactions' => $rows ?: array(),
					'application' => $application,
				), 201);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		private function upsert_case_record_and_touch_application($table, $application_id, $data, $user, $label, $expected_updated_at = null) {
			global $wpdb;
			$expected_version = $this->iso_to_mysql_datetime($expected_updated_at);

			if (false === $wpdb->query('START TRANSACTION')) {
				throw new Exception('Unable to start the ' . $label . ' case update.');
			}

			try {
				$application_sql = "UPDATE {$this->applications_table} SET lastUpdatedByName = %s, updatedAt = CURRENT_TIMESTAMP(3) WHERE id = %s";
				$application_args = array($user['name'], $application_id);
				if ($expected_version) {
					$application_sql .= ' AND updatedAt = %s';
					$application_args[] = $expected_version;
				}

				$application_written = $wpdb->query($wpdb->prepare($application_sql, $application_args));
				if (false === $application_written) {
					throw new Exception('Unable to refresh the parent application before the ' . $label . ' case update.');
				}
				if (0 === $application_written && $expected_version) {
					throw new Exception(self::STALE_APPLICATION_ERROR);
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE applicationId = %s LIMIT 1", $application_id));
				if ($existing) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$written = $wpdb->update($table, $data, array('applicationId' => $application_id));
					if (false === $written) {
						throw new Exception('Unable to update the ' . $label . ' case.');
					}
				} else {
					$data['id'] = wp_generate_uuid4();
					$data['applicationId'] = $application_id;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$written = $wpdb->insert($table, $data);
					if (false === $written || 0 === $written) {
						throw new Exception('Unable to create the ' . $label . ' case.');
					}
				}
				if (false === $wpdb->query('COMMIT')) {
					throw new Exception('Unable to commit the ' . $label . ' case update.');
				}
			} catch (Exception $write_error) {
				$wpdb->query('ROLLBACK');
				throw $write_error;
			}
		}

		private function mutation_error_status($error) {
			return self::STALE_APPLICATION_ERROR === $error->getMessage() ? 409 : 400;
		}

		private function document_mutation_error_status($error) {
			if (self::STALE_APPLICATION_ERROR === $error->getMessage()) {
				return 409;
			}

			$permission_errors = array(
				'You are not allowed to access this application.',
				'You do not have permission to upload this document type.',
				'You do not have permission to assess case documents.',
				'You do not have permission to remove this document.',
			);

			return in_array($error->getMessage(), $permission_errors, true) ? 403 : 400;
		}

		public function rest_get_migration_case(WP_REST_Request $request) {
			global $wpdb;
			try {
				$user = $this->current_session_user();
				$application_id = sanitize_text_field($request['application_id']);
				$this->get_authorized_application_base($application_id, $user);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->migration_cases_table} WHERE applicationId = %s LIMIT 1", $application_id), ARRAY_A);
				return new WP_REST_Response(array('ok' => true, 'migrationCase' => $row ?: null), 200);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_upsert_migration_case(WP_REST_Request $request) {
			global $wpdb;
			$params = $request->get_json_params();
			$draft = isset($params['draft']) ? (array) $params['draft'] : array();
			$expected_updated_at = isset($params['expectedUpdatedAt']) ? (string) $params['expectedUpdatedAt'] : null;
			try {
				$user = $this->current_session_user();
				if (!$this->can_edit_migration_or_immigration_records($user)) {
					throw new Exception('You do not have permission to update migration case details.');
				}
				$application_id = sanitize_text_field($request['application_id']);
				$this->get_authorized_application_base($application_id, $user);
				$data = array(
					'packPreparedDate' => isset($draft['packPreparedDate']) ? sanitize_text_field($draft['packPreparedDate']) : null,
					'packSubmittedDate' => isset($draft['packSubmittedDate']) ? sanitize_text_field($draft['packSubmittedDate']) : null,
					'paymentReference' => isset($draft['paymentReference']) ? sanitize_text_field($draft['paymentReference']) : null,
					'paymentDate' => isset($draft['paymentDate']) ? sanitize_text_field($draft['paymentDate']) : null,
					'decisionDate' => isset($draft['decisionDate']) ? sanitize_text_field($draft['decisionDate']) : null,
					'permitReference' => isset($draft['permitReference']) ? sanitize_text_field($draft['permitReference']) : null,
					'note' => isset($draft['note']) ? sanitize_textarea_field($draft['note']) : null,
					'recordedByName' => $user['name'],
					'updatedAt' => current_time('mysql', true),
				);
				$this->upsert_case_record_and_touch_application(
					$this->migration_cases_table,
					$application_id,
					$data,
					$user,
					'migration',
					$expected_updated_at
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->migration_cases_table} WHERE applicationId = %s LIMIT 1", $application_id), ARRAY_A);
				return new WP_REST_Response(array(
					'ok' => true,
					'migrationCase' => $row ?: null,
					'application' => $this->to_admission_case($this->get_detailed_application_record($application_id)),
				), 200);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), $this->mutation_error_status($error));
			}
		}

		public function rest_get_immigration_case(WP_REST_Request $request) {
			global $wpdb;
			try {
				$user = $this->current_session_user();
				$application_id = sanitize_text_field($request['application_id']);
				$this->get_authorized_application_base($application_id, $user);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->immigration_cases_table} WHERE applicationId = %s LIMIT 1", $application_id), ARRAY_A);
				return new WP_REST_Response(array('ok' => true, 'immigrationCase' => $row ?: null), 200);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_upsert_immigration_case(WP_REST_Request $request) {
			global $wpdb;
			$params = $request->get_json_params();
			$draft = isset($params['draft']) ? (array) $params['draft'] : array();
			$expected_updated_at = isset($params['expectedUpdatedAt']) ? (string) $params['expectedUpdatedAt'] : null;
			try {
				$user = $this->current_session_user();
				if (!$this->can_edit_migration_or_immigration_records($user)) {
					throw new Exception('You do not have permission to update immigration case details.');
				}
				$application_id = sanitize_text_field($request['application_id']);
				$this->get_authorized_application_base($application_id, $user);
				$data = array(
					'arrivalDate' => isset($draft['arrivalDate']) ? sanitize_text_field($draft['arrivalDate']) : null,
					'medicalCertDate' => isset($draft['medicalCertDate']) ? sanitize_text_field($draft['medicalCertDate']) : null,
					'xRayDate' => isset($draft['xRayDate']) ? sanitize_text_field($draft['xRayDate']) : null,
					'appointmentDate' => isset($draft['appointmentDate']) ? sanitize_text_field($draft['appointmentDate']) : null,
					'paymentReference' => isset($draft['paymentReference']) ? sanitize_text_field($draft['paymentReference']) : null,
					'insurancePolicyNumber' => isset($draft['insurancePolicyNumber']) ? sanitize_text_field($draft['insurancePolicyNumber']) : null,
					'insuranceExpirationDate' => isset($draft['insuranceExpirationDate']) ? sanitize_text_field($draft['insuranceExpirationDate']) : null,
					'pinkCardDate' => isset($draft['pinkCardDate']) ? sanitize_text_field($draft['pinkCardDate']) : null,
					'enrollmentAgreementDate' => isset($draft['enrollmentAgreementDate']) ? sanitize_text_field($draft['enrollmentAgreementDate']) : null,
					'note' => isset($draft['note']) ? sanitize_textarea_field($draft['note']) : null,
					'recordedByName' => $user['name'],
					'updatedAt' => current_time('mysql', true),
				);
				$this->upsert_case_record_and_touch_application(
					$this->immigration_cases_table,
					$application_id,
					$data,
					$user,
					'immigration',
					$expected_updated_at
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->immigration_cases_table} WHERE applicationId = %s LIMIT 1", $application_id), ARRAY_A);
				return new WP_REST_Response(array(
					'ok' => true,
					'immigrationCase' => $row ?: null,
					'application' => $this->to_admission_case($this->get_detailed_application_record($application_id)),
				), 200);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), $this->mutation_error_status($error));
			}
		}


		private function json_error_response($message, $status) {
			return new WP_REST_Response(
				array(
					'ok' => false,
					'error' => $message,
				),
				$status
			);
		}

		private function password_attempt_transient_key($user_id) {
			return 'mc_admissions_password_attempts_' . (int) $user_id;
		}

		private function failed_password_attempt_count($user_id) {
			$stored_attempts = get_transient($this->password_attempt_transient_key($user_id));

			return is_numeric($stored_attempts) ? max(0, (int) $stored_attempts) : 0;
		}

		private function record_failed_password_attempt($user_id, $current_attempts) {
			$attempts = max(0, (int) $current_attempts) + 1;
			set_transient(
				$this->password_attempt_transient_key($user_id),
				$attempts,
				self::PASSWORD_ATTEMPT_WINDOW_SECONDS
			);

			return $attempts;
		}

		private function password_response($ok, $message, $status) {
			return new WP_REST_Response(
				array(
					'ok' => (bool) $ok,
					'message' => (string) $message,
				),
				(int) $status
			);
		}
	}
}

function mc_admissions_wordpress_backend() {
	static $plugin = null;

	if (null === $plugin) {
		$plugin = new MC_Admissions_WordPress_Backend();
		$plugin->boot();
	}

	return $plugin;
}

register_activation_hook(__FILE__, array(mc_admissions_wordpress_backend(), 'activate'));
mc_admissions_wordpress_backend();
