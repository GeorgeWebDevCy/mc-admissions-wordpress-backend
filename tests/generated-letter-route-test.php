<?php

$source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');

if (false === $source) {
	fwrite(STDERR, "Unable to read plugin source.\n");
	exit(1);
}

$required_markers = array(
	"'/applications/(?P<application_id>[A-Za-z0-9_-]+)/letters'",
	"'callback' => array(\$this, 'rest_generate_admission_letter')",
	"'expectedUpdatedAt'",
	"private function generated_admission_letter_template_labels",
	"'offer-letter' => 'Offer letter'",
	"'payment-receipt' => 'Payment receipt'",
	"'acceptance-letter' => 'Acceptance letter'",
	"'letter-of-assurance' => 'Letter of assurance'",
	"'late-arrival-affirmation-letter' => 'Late arrival affirmation letter'",
	"private function can_generate_admission_letter",
	"private function assert_admission_letter_generation_available",
	"private function persist_generated_admission_letter",
	"if (!\$this->can_generate_admission_letter(\$user, \$template_id))",
	"\$this->assert_admission_letter_generation_available(\$application, \$template_id)",
	"if ((string) \$locked_application['updatedAt'] !== (string) \$expected_version)",
	"throw new Exception(self::STALE_APPLICATION_ERROR)",
	"'%PDF-' !== substr(\$content, 0, 5)",
	"'mc_generated_letters'",
	"WHERE id = %s AND status IN ('offer-issued', 'prepayment-pending', 'Offer letter issued', 'Payment pending')",
	"private function send_generated_admission_letter_email",
	"\$agency_email_is_student = is_email(\$student_email)",
	"strtolower(\$student_email) === strtolower(\$agency_email)",
	"The agency email matches the student email, so delivery was skipped.",
	"\$delivery_skipped = true",
	"No valid originating agency email is recorded.",
	"wp_mail(array(\$recipient['email']), \$subject, \$html_message, \$headers, \$attachments)",
	"\$this->record_application_activity_alert(",
	"'acceptance generated internal role handoff'",
	"\$this->send_application_role_notification(\$post_commit_application, \$user, \$role_payload)",
	"instead of throwing a retryable error that could duplicate the PDF/email",
	"\$post_commit_application['generatedLetters'][] = \$fallback_letter",
);

foreach ($required_markers as $marker) {
	if (false === strpos($source, $marker)) {
		fwrite(STDERR, "Missing generated-letter route contract: {$marker}\n");
		exit(1);
	}
}

if (false !== strpos($source, 'Mobile web generation currently supports the Offer Letter only.')) {
	fwrite(STDERR, "Generated-letter route still rejects non-offer templates.\n");
	exit(1);
}

$email_start = strpos($source, 'private function send_generated_admission_letter_email');
$email_end = strpos($source, 'private function get_detailed_application_record', $email_start);
$email_source = false !== $email_start && false !== $email_end
	? substr($source, $email_start, $email_end - $email_start)
	: '';
if (false !== strpos($email_source, 'PRESIDENT_ACTIVITY_ALERT_EMAIL')) {
	fwrite(STDERR, "Generated official letters must only be sent to the originating agency.\n");
	exit(1);
}

echo "Generated letter route contract tests passed.\n";
