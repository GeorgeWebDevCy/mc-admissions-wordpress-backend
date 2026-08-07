<?php

$source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');

if (false === $source) {
	fwrite(STDERR, "Unable to read plugin source.\n");
	exit(1);
}

$required_markers = array(
	"'/applications/(?P<application_id>[A-Za-z0-9_-]+)/letters'",
	"'callback' => array(\$this, 'rest_generate_admission_letter')",
	"private function persist_generated_admission_letter",
	"'offer-letter' !== \$template_id",
	"'%PDF-' !== substr(\$content, 0, 5)",
	"'mc_generated_letters'",
	"private function send_generated_offer_letter_email",
	"wp_mail(array(\$recipient['email']), \$subject, \$html_message, \$headers, \$attachments)",
);

foreach ($required_markers as $marker) {
	if (false === strpos($source, $marker)) {
		fwrite(STDERR, "Missing generated-letter route contract: {$marker}\n");
		exit(1);
	}
}

echo "Generated letter route contract tests passed.\n";
