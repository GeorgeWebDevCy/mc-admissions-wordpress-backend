<?php
/**
 * Plugin Name: MC Admissions WordPress Backend
 * Plugin URI: https://www.mesoyios.ac.cy/
 * Description: WordPress REST backend for the MC Admissions desktop app.
 * Version: 0.2.16
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

		/** @var string */
		private $applications_table = 'mc_admission_applications';

		/** @var string */
		private $documents_table = 'mc_admission_documents';

		/** @var string */
		private $activities_table = 'mc_admission_activities';

		/** @var string */
		private $settings_table = 'mc_admission_settings';

		/** @var string */
		private $payments_table = 'mc_admission_payments';

		/** @var string */
		private $migration_cases_table = 'mc_admission_migration_cases';

		/** @var string */
		private $immigration_cases_table = 'mc_admission_immigration_cases';

		/** @var string */
		private $agency_profiles_table = 'mc_agency_profiles';

		/** @var string[] */
		private $document_requirements = array(
			'passport' => 'Copy of passport',
			'secondaryMarksheet' => 'Copy of Secondary School (10th grade) marksheet',
			'higherSecondaryMarksheet' => 'Copy of Higher Secondary School (12th grade) marksheet',
			'englishCertificate' => 'English proficiency certificate',
			'studentSignature' => 'Student signature',
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

		public function boot() {
			$this->ensure_roles();
			$this->ensure_immigration_insurance_columns();
			$this->boot_update_checker();
			add_filter('upgrader_source_selection', array($this, 'normalize_update_package_paths'), 10, 4);
			add_action('admin_menu', array($this, 'register_admin_menu'));
			add_action('rest_api_init', array($this, 'register_rest_routes'));
			add_filter('rest_pre_serve_request', array($this, 'send_rest_cors_headers'), 10, 4);
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
					programmeCode VARCHAR(191) NOT NULL,
					programmeLabel VARCHAR(191) NOT NULL,
					agencyName VARCHAR(191) NOT NULL,
					consultantName VARCHAR(191) NOT NULL,
					tuitionAcknowledged BOOLEAN NOT NULL,
					offerTermsAcknowledged BOOLEAN NOT NULL,
					gdprAcknowledged BOOLEAN NOT NULL,
					status VARCHAR(191) NOT NULL DEFAULT 'Application in progress',
					workflowNote TEXT NULL,
					lastUpdatedByName VARCHAR(191) NULL,
					reviewSummary TEXT NULL,
					reviewerDecision VARCHAR(191) NOT NULL DEFAULT 'pending',
					decisionDueDate VARCHAR(191) NULL,
					offerIssuedDate VARCHAR(191) NULL,
					offerExpiryDate VARCHAR(191) NULL,
					offerConditionNote TEXT NULL,
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
					source VARCHAR(191) NOT NULL DEFAULT 'mc-admissions-wordpress',
					createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					updatedAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (id),
					UNIQUE KEY mc_admission_applications_referenceCode_key (referenceCode)
				) {$charset}
				",
				"
				CREATE TABLE IF NOT EXISTS {$this->documents_table} (
					id VARCHAR(191) NOT NULL,
					applicationId VARCHAR(191) NOT NULL,
					type VARCHAR(191) NOT NULL,
					label VARCHAR(191) NOT NULL,
					isReady BOOLEAN NOT NULL DEFAULT FALSE,
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
					KEY mc_admission_documents_applicationId_idx (applicationId)
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
					createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
					PRIMARY KEY (id),
					KEY mc_admission_activities_applicationId_createdAt_idx (applicationId, createdAt)
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

			return $source;
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

				$saved = true;
			}

			$tenant_id = $this->get_setting('m365_tenant_id');
			$client_id = $this->get_setting('m365_client_id');
			$client_secret = $this->get_setting('m365_client_secret');
			$drive_id = $this->get_setting('m365_drive_id');
			$document_root = $this->get_setting('m365_document_root', 'Admissions');
			$github_token = $this->get_setting('github_token');
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
				'/email',
				array(
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => array($this, 'rest_send_email'),
					'permission_callback' => array($this, 'permission_authenticated'),
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
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => array($this, 'rest_upload_document'),
					'permission_callback' => array($this, 'permission_authenticated'),
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
			header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
			header('Vary: Origin');

			if ('OPTIONS' === strtoupper($_SERVER['REQUEST_METHOD'])) {
				status_header(204);
				return true;
			}

			return $served;
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
						"SELECT COUNT(*) FROM {$letters_table} WHERE templateId = %s",
						$template_id
					)
				);
			}

			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$letters_table} letter
					INNER JOIN {$this->applications_table} app ON app.id = letter.applicationId
					WHERE letter.templateId = %s AND app.wordpressUserId = %d",
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
						'mode' => (string) $params['mode'],
						'draft' => (array) $params['draft'],
						'user' => $user,
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
				return $this->json_error_response($error->getMessage(), 400);
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

			$wpdb->query(
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

		private function workflow_note_for_status($status) {
			switch ($status) {
				case 'trashed':
					return 'Application moved to Trash by an administrator.';
				case 'review-pending':
					return 'Application restored from Trash and returned to pending assessment.';
				case 'Application in progress':
					return 'Application is being prepared. Complete the profile and document pack before review.';
				case 'Under review':
					return 'Application is queued for assessment and document verification.';
				case 'Offer letter issued':
					return 'Offer issued. Send payment and acceptance instructions to the applicant.';
				case 'Payment pending':
					return 'Waiting for tuition receipt or finance confirmation.';
				case 'Acceptance confirmed':
					return 'Offer accepted. Prepare the permit and pre-arrival file.';
				case 'Entry permit processing':
					return 'Permit pack submitted. Monitor approvals and travel readiness.';
				case 'Ready to enroll':
					return 'Admissions process complete. Hand over to enrollment and registrar.';
				default:
					return 'Application is being prepared. Complete the profile and document pack before review.';
			}
		}

		private function next_action_for_status($application, $ready_documents, $total_documents) {
			$missing_docs = max(0, $total_documents - $ready_documents);
			$status = $this->normalize_status($application['status']);

			switch ($status) {
				case 'Application in progress':
					return $missing_docs > 0
						? 'Complete the applicant profile and clear the missing document slots.'
						: 'Submit the case into review.';
				case 'Under review':
					return $missing_docs > 0
						? 'Request the outstanding documents before confirming the review outcome.'
						: ('pending' === $application['reviewerDecision']
							? 'Record the academic decision and issue the offer.'
							: 'Issue the offer letter and set the payment instructions.');
				case 'Offer letter issued':
					return 'cleared' === $application['paymentStatus']
						? 'Move the cleared case into acceptance confirmation.'
						: 'Collect tuition payment evidence and signed acceptance.';
				case 'Payment pending':
					return 'cleared' === $application['paymentStatus']
						? 'Confirm acceptance and begin permit processing.'
						: 'Wait for finance clearance or receipt verification.';
				case 'Acceptance confirmed':
					return 'submitted' === $application['permitStatus']
						? 'Track the permit outcome and prepare arrival planning.'
						: 'Prepare and submit the entry permit pack.';
				case 'Entry permit processing':
					return 'approved' === $application['permitStatus']
						? 'Capture travel and orientation details, then hand off to enrollment.'
						: 'Monitor permit status and keep the applicant updated.';
				case 'Ready to enroll':
					return 'enrolled' === $application['enrollmentStatus']
						? 'Case completed. Keep the record for audit and reporting.'
						: 'Finalize registrar handoff and orientation scheduling.';
				default:
					return 'Complete the applicant profile and clear the missing document slots.';
			}
		}

		private function get_lane_for_status($status) {
			switch ($status) {
				case 'Application in progress':
					return 'incoming';
				case 'Under review':
				case 'Offer letter issued':
					return 'review';
				case 'Payment pending':
				case 'Acceptance confirmed':
				case 'Entry permit processing':
				case 'Ready to enroll':
					return 'arrival';
				default:
					return 'incoming';
			}
		}

		private function get_progress_for_status($status, $ready_documents) {
			$by_status = array(
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

			if (!$this->can_view_all_applications($user)) {
				$where_sql = 'WHERE app.wordpressUserId = %d';
				$query_args[] = (int) $user['id'];
			}

			// High safety cap only; the board shows every application (matches the desktop, which has no limit).
			$query_args[] = 5000;

			$sql = "
				SELECT
					app.*,
					MAX(migration.packSubmittedDate) AS permitPackSubmittedDate,
					MAX(migration.paymentReference) AS permitPaymentReference,
					MAX(migration.paymentDate) AS permitPaymentDate,
					MAX(migration.decisionDate) AS permitDecisionDate,
					MAX(migration.permitReference) AS permitReference,
					COUNT(doc.id) AS documentCount,
					COALESCE(SUM(CASE WHEN doc.isReady = 1 THEN 1 ELSE 0 END), 0) AS readyDocumentCount
				FROM {$this->applications_table} app
				LEFT JOIN {$this->documents_table} doc
					ON doc.applicationId = app.id
				LEFT JOIN {$this->migration_cases_table} migration
					ON migration.applicationId = app.id
				{$where_sql}
				GROUP BY app.id
				ORDER BY app.updatedAt DESC
				LIMIT %d
			";

			$prepared = $wpdb->prepare($sql, $query_args);
			$rows = $wpdb->get_results($prepared, ARRAY_A);

			return array_map(array($this, 'to_board_application'), is_array($rows) ? $rows : array());
		}

		private function to_board_application($application) {
			$ready_documents = isset($application['readyDocumentCount']) ? (int) $application['readyDocumentCount'] : 0;
			$total_documents = isset($application['documentCount']) ? (int) $application['documentCount'] : 0;
			$status = $this->normalize_status($application['status']);
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
				'lane' => $this->get_lane_for_status($status),
				'progress' => $this->get_progress_for_status($status, $ready_documents),
				'missingDocs' => $missing_docs,
				'readyDocuments' => $ready_documents,
				'nextAction' => $this->next_action_for_status($application, $ready_documents, $total_documents),
				'workflowNote' => !empty($application['workflowNote']) ? $application['workflowNote'] : null,
				'updatedByName' => !empty($application['lastUpdatedByName']) ? $application['lastUpdatedByName'] : null,
				'updatedAt' => $this->mysql_datetime_to_iso($application['updatedAt']),
				'isLive' => true,
			);
		}

		private function get_authorized_application_base($application_id, $user) {
			global $wpdb;

			$application = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, wordpressUserId FROM {$this->applications_table} WHERE id = %s LIMIT 1",
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
				'uploadedUrl' => !empty($document['uploadedUrl']) ? $document['uploadedUrl'] : null,
				'originalName' => !empty($document['originalName']) ? $document['originalName'] : null,
				'mimeType' => !empty($document['mimeType']) ? $document['mimeType'] : null,
				'fileSizeBytes' => isset($document['fileSizeBytes']) ? (int) $document['fileSizeBytes'] : null,
				'uploadedAt' => !empty($document['uploadedAt']) ? $document['uploadedAt'] : null,
				'uploadedByName' => !empty($document['uploadedByName']) ? $document['uploadedByName'] : null,
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
			$activity = array_map(
				array($this, 'map_activity_entry'),
				$application['activities']
			);

			return array_merge(
				$board,
				array(
					'fullName' => $application['fullName'],
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
					'programmeCode' => $application['programmeCode'],
					'consultantName' => $application['consultantName'],
					'reviewSummary' => !empty($application['reviewSummary']) ? $application['reviewSummary'] : null,
					'reviewerDecision' => $application['reviewerDecision'],
					'decisionDueDate' => !empty($application['decisionDueDate']) ? $application['decisionDueDate'] : null,
					'offerIssuedDate' => !empty($application['offerIssuedDate']) ? $application['offerIssuedDate'] : null,
					'offerExpiryDate' => !empty($application['offerExpiryDate']) ? $application['offerExpiryDate'] : null,
					'offerConditionNote' => !empty($application['offerConditionNote']) ? $application['offerConditionNote'] : null,
					'paymentStatus' => $application['paymentStatus'],
					'paymentAmount' => !empty($application['paymentAmount']) ? $application['paymentAmount'] : null,
					'paymentCurrency' => $application['paymentCurrency'],
					'paymentReference' => !empty($application['paymentReference']) ? $application['paymentReference'] : null,
					'paymentConfirmedDate' => !empty($application['paymentConfirmedDate']) ? $application['paymentConfirmedDate'] : null,
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
					'documents' => $documents,
					'activity' => $activity,
					'createdAt' => $this->mysql_datetime_to_iso($application['createdAt']),
				)
			);
		}

		private function map_activity_entry($entry) {
			return array(
				'id' => $entry['id'],
				'kind' => $entry['kind'],
				'title' => $entry['title'],
				'detail' => !empty($entry['detail']) ? $entry['detail'] : null,
				'actorName' => $entry['actorName'],
				'createdAt' => $this->mysql_datetime_to_iso($entry['createdAt']),
			);
		}

		private function create_activity($application_id, $user, $kind, $title, $detail = null) {
			global $wpdb;

			$wpdb->insert(
				$this->activities_table,
				array(
					'id' => wp_generate_uuid4(),
					'applicationId' => $application_id,
					'kind' => $kind,
					'title' => $title,
					'detail' => $this->trim_to_null($detail),
					'actorName' => $user['name'],
					'createdAt' => current_time('mysql', true),
				),
				array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
			);
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

		private function normalize_operations_draft($draft, $fallback_status) {
			return array(
				'workflowNote' => $this->trim_to_null(isset($draft['workflowNote']) ? $draft['workflowNote'] : null) ?: $this->workflow_note_for_status($fallback_status),
				'reviewerDecision' => $this->normalize_select_value(isset($draft['reviewerDecision']) ? $draft['reviewerDecision'] : '', $this->reviewer_decisions, 'pending'),
				'reviewSummary' => $this->trim_to_null(isset($draft['reviewSummary']) ? $draft['reviewSummary'] : null),
				'decisionDueDate' => $this->trim_to_null(isset($draft['decisionDueDate']) ? $draft['decisionDueDate'] : null),
				'offerIssuedDate' => $this->trim_to_null(isset($draft['offerIssuedDate']) ? $draft['offerIssuedDate'] : null),
				'offerExpiryDate' => $this->trim_to_null(isset($draft['offerExpiryDate']) ? $draft['offerExpiryDate'] : null),
				'offerConditionNote' => $this->trim_to_null(isset($draft['offerConditionNote']) ? $draft['offerConditionNote'] : null),
				'paymentStatus' => $this->normalize_select_value(isset($draft['paymentStatus']) ? $draft['paymentStatus'] : '', $this->payment_statuses, 'awaiting-invoice'),
				'paymentAmount' => $this->trim_to_null(isset($draft['paymentAmount']) ? $draft['paymentAmount'] : null),
				'paymentCurrency' => $this->trim_to_null(isset($draft['paymentCurrency']) ? $draft['paymentCurrency'] : null) ?: 'EUR',
				'paymentReference' => $this->trim_to_null(isset($draft['paymentReference']) ? $draft['paymentReference'] : null),
				'paymentConfirmedDate' => $this->trim_to_null(isset($draft['paymentConfirmedDate']) ? $draft['paymentConfirmedDate'] : null),
				'financeNote' => $this->trim_to_null(isset($draft['financeNote']) ? $draft['financeNote'] : null),
				'permitStatus' => $this->normalize_select_value(isset($draft['permitStatus']) ? $draft['permitStatus'] : '', $this->permit_statuses, 'not-started'),
				'permitReference' => $this->trim_to_null(isset($draft['permitReference']) ? $draft['permitReference'] : null),
				'permitSubmittedDate' => $this->trim_to_null(isset($draft['permitSubmittedDate']) ? $draft['permitSubmittedDate'] : null),
				'permitDecisionDate' => $this->trim_to_null(isset($draft['permitDecisionDate']) ? $draft['permitDecisionDate'] : null),
				'permitNote' => $this->trim_to_null(isset($draft['permitNote']) ? $draft['permitNote'] : null),
				'arrivalStatus' => $this->normalize_select_value(isset($draft['arrivalStatus']) ? $draft['arrivalStatus'] : '', $this->arrival_statuses, 'planning'),
				'travelDate' => $this->trim_to_null(isset($draft['travelDate']) ? $draft['travelDate'] : null),
				'accommodationStatus' => $this->trim_to_null(isset($draft['accommodationStatus']) ? $draft['accommodationStatus'] : null),
				'enrollmentStatus' => $this->normalize_select_value(isset($draft['enrollmentStatus']) ? $draft['enrollmentStatus'] : '', $this->enrollment_statuses, 'pending'),
				'orientationDate' => $this->trim_to_null(isset($draft['orientationDate']) ? $draft['orientationDate'] : null),
				'enrollmentNote' => $this->trim_to_null(isset($draft['enrollmentNote']) ? $draft['enrollmentNote'] : null),
			);
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

			$wpdb->query('START TRANSACTION');

			try {
				if ($record_id) {
					$this->get_authorized_application_base($record_id, $user);

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
							programmeCode = %s,
							programmeLabel = %s,
							agencyName = %s,
							consultantName = %s,
							tuitionAcknowledged = %d,
							offerTermsAcknowledged = %d,
							gdprAcknowledged = %d,
							status = %s,
							workflowNote = %s,
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
						$this->trim_to_empty(isset($draft['programme']) ? $draft['programme'] : ''),
						$this->programme_label_from_code(isset($draft['programme']) ? $draft['programme'] : ''),
						$this->trim_to_empty(isset($draft['agencyName']) ? $draft['agencyName'] : ''),
						$this->trim_to_empty(isset($draft['consultantName']) ? $draft['consultantName'] : ''),
						!empty($draft['tuitionAcknowledged']) ? 1 : 0,
						!empty($draft['offerTermsAcknowledged']) ? 1 : 0,
						!empty($draft['gdprAcknowledged']) ? 1 : 0,
						$status,
						$this->workflow_note_for_status($status),
						$user['name'],
						$record_id,
					);

					if ($expected_version) {
						$update_sql .= " AND updatedAt = %s";
						$args[] = $expected_version;
					}

					$updated = $wpdb->query($wpdb->prepare($update_sql, $args));

					if (0 === $updated && $expected_version) {
						throw new Exception(self::STALE_APPLICATION_ERROR);
					}

					$this->sync_document_checklist($record_id, isset($draft['documents']) ? (array) $draft['documents'] : array());

					$this->create_activity(
						$record_id,
						$user,
						'review' === $mode ? 'workflow' : 'application',
						'review' === $mode ? 'Application staged for review' : 'Application saved',
						'review' === $mode
							? 'The case moved into review with the current applicant profile and document checklist.'
							: 'Application details were updated from the admissions workspace.'
					);
				} else {
					$record_id = wp_generate_uuid4();

					$wpdb->insert(
						$this->applications_table,
						array(
							'id' => $record_id,
							'referenceCode' => $this->generate_reference_code(),
							'wordpressUserId' => (int) $user['id'],
							'wordpressUsername' => $user['username'],
							'wordpressEmail' => $user['email'],
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
							'programmeCode' => $this->trim_to_empty(isset($draft['programme']) ? $draft['programme'] : ''),
							'programmeLabel' => $this->programme_label_from_code(isset($draft['programme']) ? $draft['programme'] : ''),
							'agencyName' => $this->trim_to_empty(isset($draft['agencyName']) ? $draft['agencyName'] : ''),
							'consultantName' => $this->trim_to_empty(isset($draft['consultantName']) ? $draft['consultantName'] : ''),
							'tuitionAcknowledged' => !empty($draft['tuitionAcknowledged']) ? 1 : 0,
							'offerTermsAcknowledged' => !empty($draft['offerTermsAcknowledged']) ? 1 : 0,
							'gdprAcknowledged' => !empty($draft['gdprAcknowledged']) ? 1 : 0,
							'status' => $status,
							'workflowNote' => $this->workflow_note_for_status($status),
							'lastUpdatedByName' => $user['name'],
							'source' => self::DEFAULT_SOURCE,
							'createdAt' => current_time('mysql', true),
							'updatedAt' => current_time('mysql', true),
						)
					);

					$this->sync_document_checklist($record_id, isset($draft['documents']) ? (array) $draft['documents'] : array());

					$this->create_activity(
						$record_id,
						$user,
						'review' === $mode ? 'workflow' : 'application',
						'review' === $mode ? 'Application submitted for review' : 'Application created',
						'review' === $mode
							? 'A new application was submitted into the review queue from the intake form.'
							: 'A new admissions case was created from the desktop intake form.'
					);
				}

				$wpdb->query('COMMIT');
			} catch (Exception $error) {
				$wpdb->query('ROLLBACK');
				throw $error;
			}

			$application = $this->get_detailed_application_record($record_id);

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
					"SELECT id, wordpressUserId, status, workflowNote FROM {$this->applications_table} WHERE id = %s LIMIT 1",
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

			if ($expected_version) {
				$update_sql .= " AND updatedAt = %s";
				$args[] = $expected_version;
			}

			$updated = $wpdb->query($wpdb->prepare($update_sql, $args));

			if (0 === $updated && $expected_version) {
				throw new Exception(self::STALE_APPLICATION_ERROR);
			}

			$status_changed = $existing['status'] !== $status;
			$note_changed = (string) $existing['workflowNote'] !== (string) $next_note;

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
			);
		}

		private function update_admission_application_operations($params) {
			global $wpdb;

			$user = $params['user'];
			$application_id = $params['applicationId'];
			$expected_version = $this->iso_to_mysql_datetime($params['expectedUpdatedAt']);
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"
					SELECT id, wordpressUserId, status, paymentStatus, permitStatus, enrollmentStatus
					FROM {$this->applications_table}
					WHERE id = %s
					LIMIT 1
					",
					$application_id
				),
				ARRAY_A
			);

			if (!$existing) {
				throw new Exception('Application not found.');
			}

			if (!$this->can_view_all_applications($user) && (int) $existing['wordpressUserId'] !== (int) $user['id']) {
				throw new Exception('You are not allowed to update this application.');
			}

			$normalized = $this->normalize_operations_draft($params['draft'], $this->normalize_status($existing['status']));

			$update_sql = "
				UPDATE {$this->applications_table}
				SET
					workflowNote = %s,
					reviewerDecision = %s,
					reviewSummary = %s,
					decisionDueDate = %s,
					offerIssuedDate = %s,
					offerExpiryDate = %s,
					offerConditionNote = %s,
					paymentStatus = %s,
					paymentAmount = %s,
					paymentCurrency = %s,
					paymentReference = %s,
					paymentConfirmedDate = %s,
					financeNote = %s,
					permitStatus = %s,
					permitReference = %s,
					permitSubmittedDate = %s,
					permitDecisionDate = %s,
					permitNote = %s,
					arrivalStatus = %s,
					travelDate = %s,
					accommodationStatus = %s,
					enrollmentStatus = %s,
					orientationDate = %s,
					enrollmentNote = %s,
					lastUpdatedByName = %s,
					updatedAt = CURRENT_TIMESTAMP(3)
				WHERE id = %s
			";

			$args = array(
				$normalized['workflowNote'],
				$normalized['reviewerDecision'],
				$normalized['reviewSummary'],
				$normalized['decisionDueDate'],
				$normalized['offerIssuedDate'],
				$normalized['offerExpiryDate'],
				$normalized['offerConditionNote'],
				$normalized['paymentStatus'],
				$normalized['paymentAmount'],
				$normalized['paymentCurrency'],
				$normalized['paymentReference'],
				$normalized['paymentConfirmedDate'],
				$normalized['financeNote'],
				$normalized['permitStatus'],
				$normalized['permitReference'],
				$normalized['permitSubmittedDate'],
				$normalized['permitDecisionDate'],
				$normalized['permitNote'],
				$normalized['arrivalStatus'],
				$normalized['travelDate'],
				$normalized['accommodationStatus'],
				$normalized['enrollmentStatus'],
				$normalized['orientationDate'],
				$normalized['enrollmentNote'],
				$user['name'],
				$application_id,
			);

			if ($expected_version) {
				$update_sql .= " AND updatedAt = %s";
				$args[] = $expected_version;
			}

			$updated = $wpdb->query($wpdb->prepare($update_sql, $args));

			if (0 === $updated && $expected_version) {
				throw new Exception(self::STALE_APPLICATION_ERROR);
			}

			$detail_parts = array();
			if ($existing['paymentStatus'] !== $normalized['paymentStatus']) {
				$detail_parts[] = 'payment ' . $existing['paymentStatus'] . ' -> ' . $normalized['paymentStatus'];
			}
			if ($existing['permitStatus'] !== $normalized['permitStatus']) {
				$detail_parts[] = 'permit ' . $existing['permitStatus'] . ' -> ' . $normalized['permitStatus'];
			}
			if ($existing['enrollmentStatus'] !== $normalized['enrollmentStatus']) {
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

			$this->get_authorized_application_base($application_id, $user);

			if (!isset($this->document_requirements[$document_type])) {
				throw new Exception('Unknown document type.');
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

			$wpdb->query('START TRANSACTION');

			try {
				$wpdb->query(
					$wpdb->prepare(
						"
						INSERT INTO {$this->documents_table}
							(id, applicationId, type, label, isReady, uploadedUrl, storedFilename, storageProvider, storageDriveId, storageItemId, storagePath, storageWebUrl, originalName, mimeType, fileSizeBytes, uploadedAt, uploadedByName, createdAt, updatedAt)
						VALUES
							(%s, %s, %s, %s, 1, %s, %s, 'microsoft-365', %s, %s, %s, %s, %s, %s, %d, %s, %s, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
						ON DUPLICATE KEY UPDATE
							label = VALUES(label),
							isReady = VALUES(isReady),
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

				$wpdb->query(
					$wpdb->prepare(
						"
						UPDATE {$this->applications_table}
						SET lastUpdatedByName = %s, updatedAt = CURRENT_TIMESTAMP(3)
						WHERE id = %s
						",
						$user['name'],
						$application_id
					)
				);

				$this->create_activity(
					$application_id,
					$user,
					'document',
					$this->document_requirements[$document_type] . ' uploaded',
					$file_name . ' attached to the case file.'
				);

				$wpdb->query('COMMIT');
			} catch (Exception $error) {
				$wpdb->query('ROLLBACK');
				$this->delete_document_file($stored_file['storageDriveId'], $stored_file['storageItemId']);
				throw $error;
			}

			if (!empty($existing['storageItemId'])) {
				$this->delete_document_file($existing['storageDriveId'], $existing['storageItemId']);
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
				return;
			}

			try {
				$config = $this->get_m365_config();
				$token = $this->get_m365_access_token($config);

				wp_remote_request(
					'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($drive_id) . '/items/' . rawurlencode($item_id),
					array(
						'method' => 'DELETE',
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
						),
						'timeout' => 30,
					)
				);
			} catch (Exception $error) {
				// Ignore remote cleanup failures after the new file is already stored.
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
				$application_id = sanitize_text_field($request['application_id']);
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
				$application_id = sanitize_text_field($request['application_id']);
				$id = wp_generate_uuid4();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert($this->payments_table, array(
					'id' => $id, 'applicationId' => $application_id,
					'amount' => sanitize_text_field($draft['amount']),
					'currency' => sanitize_text_field($draft['currency'] ?? 'EUR'),
					'reference' => isset($draft['reference']) ? sanitize_text_field($draft['reference']) : null,
					'swiftReference' => isset($draft['swiftReference']) ? sanitize_text_field($draft['swiftReference']) : null,
					'confirmedDate' => isset($draft['confirmedDate']) ? sanitize_text_field($draft['confirmedDate']) : null,
					'recordedByName' => $user['name'],
					'note' => isset($draft['note']) ? sanitize_textarea_field($draft['note']) : null,
				));
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results($wpdb->prepare("SELECT id, amount, currency, reference, swiftReference, confirmedDate, recordedByName, note, createdAt FROM {$this->payments_table} WHERE applicationId = %s ORDER BY createdAt DESC LIMIT 24", $application_id), ARRAY_A);
				return new WP_REST_Response(array('ok' => true, 'transactions' => $rows ?: array()), 201);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_get_migration_case(WP_REST_Request $request) {
			global $wpdb;
			try {
				$application_id = sanitize_text_field($request['application_id']);
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
			try {
				$user = $this->current_session_user();
				$application_id = sanitize_text_field($request['application_id']);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->migration_cases_table} WHERE applicationId = %s LIMIT 1", $application_id));
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
				if ($existing) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update($this->migration_cases_table, $data, array('applicationId' => $application_id));
				} else {
					$data['id'] = wp_generate_uuid4();
					$data['applicationId'] = $application_id;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->insert($this->migration_cases_table, $data);
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->migration_cases_table} WHERE applicationId = %s LIMIT 1", $application_id), ARRAY_A);
				return new WP_REST_Response(array('ok' => true, 'migrationCase' => $row ?: null), 200);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
			}
		}

		public function rest_get_immigration_case(WP_REST_Request $request) {
			global $wpdb;
			try {
				$application_id = sanitize_text_field($request['application_id']);
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
			try {
				$user = $this->current_session_user();
				$application_id = sanitize_text_field($request['application_id']);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->immigration_cases_table} WHERE applicationId = %s LIMIT 1", $application_id));
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
				if ($existing) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update($this->immigration_cases_table, $data, array('applicationId' => $application_id));
				} else {
					$data['id'] = wp_generate_uuid4();
					$data['applicationId'] = $application_id;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->insert($this->immigration_cases_table, $data);
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->immigration_cases_table} WHERE applicationId = %s LIMIT 1", $application_id), ARRAY_A);
				return new WP_REST_Response(array(
					'ok' => true,
					'immigrationCase' => $row ?: null,
					'application' => $this->to_admission_case($this->get_detailed_application_record($application_id)),
				), 200);
			} catch (Exception $error) {
				return $this->json_error_response($error->getMessage(), 400);
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
