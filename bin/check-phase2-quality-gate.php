#!/usr/bin/env php
<?php
/**
 * Deterministic Phase 2 privacy/security/accessibility/i18n quality gate.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

/**
 * Read a repository file as text.
 */
function connectlibrary_phase2_gate_read( string $root, string $relative_path ): string {
	$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
	return is_file( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * Test whether all terms are present, case-insensitively.
 *
 * @param array<int,string> $terms Required terms.
 */
function connectlibrary_phase2_gate_has_terms( string $text, array $terms ): bool {
	$haystack = strtolower( $text );
	foreach ( $terms as $term ) {
		if ( ! str_contains( $haystack, strtolower( $term ) ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Print one PASS/FAIL line and return the boolean result.
 */
function connectlibrary_phase2_gate_report( string $label, bool $passed, string $detail = '' ): bool {
	$status = $passed ? 'PASS' : 'FAIL';
	echo $status . ': ' . $label;
	if ( '' !== $detail ) {
		echo ' - ' . $detail;
	}
	echo PHP_EOL;
	return $passed;
}

$plan        = connectlibrary_phase2_gate_read( $root, 'docs/phase-2-privacy-security-accessibility-i18n-test-plan.md' );
$readme      = connectlibrary_phase2_gate_read( $root, 'README.md' );
$development = connectlibrary_phase2_gate_read( $root, 'docs/development.md' );
$a11y_i18n   = connectlibrary_phase2_gate_read( $root, 'docs/accessibility-i18n-checklist.md' );
$composer    = connectlibrary_phase2_gate_read( $root, 'composer.json' );
$includes    = '';
$iterator    = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root . DIRECTORY_SEPARATOR . 'includes', FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
		$includes .= "\n" . (string) file_get_contents( $file->getPathname() );
	}
}

$checks   = array();
$checks[] = connectlibrary_phase2_gate_report(
	'Phase 2 QA plan exists',
	'' !== $plan,
	'docs/phase-2-privacy-security-accessibility-i18n-test-plan.md'
);
$checks[] = connectlibrary_phase2_gate_report(
	'Plan covers all required roles',
	connectlibrary_phase2_gate_has_terms( $plan, array( 'Public/guest', 'Borrower', 'Guardian', 'Librarian', 'Admin', 'Unauthorized' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Plan covers privacy/security and authorization boundaries',
	connectlibrary_phase2_gate_has_terms( $plan, array( 'privacy', 'security', 'capability', 'nonce', 'REST', 'object authorization', 'permission_callback' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Plan covers escaping, sanitization, CSV formula safety, and prepared SQL',
	connectlibrary_phase2_gate_has_terms( $plan, array( 'escaping', 'sanitization', 'CSV formula safety', '$wpdb->prepare()' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Plan covers accessibility focus/forms/live-regions/tables/date text',
	connectlibrary_phase2_gate_has_terms( $plan, array( 'keyboard', 'focus', 'Forms', 'Live regions', 'tables', 'Date text' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Plan covers i18n text-domain, translator comments, localized dates, plurals, JS, and email strings',
	connectlibrary_phase2_gate_has_terms( $plan, array( 'connectlibrary', 'translator comments', 'localized', 'Plurals', 'JavaScript', 'Email' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Plan requires synthetic .test fixtures only',
	connectlibrary_phase2_gate_has_terms( $plan, array( 'synthetic fixtures only', 'example.test', 'Never paste real church member' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Plan includes manual QA, automated/static checks, known pending gaps, and explicit no Offline/PWA',
	connectlibrary_phase2_gate_has_terms( $plan, array( 'Manual QA checklist', 'Automated and static checks', 'Known pending gaps', 'Offline/PWA remains Phase 5 only' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Composer exposes phase2:quality-gate and composer check includes it',
	connectlibrary_phase2_gate_has_terms( $composer, array( 'phase2:quality-gate', 'bin/check-phase2-quality-gate.php', '@phase2:quality-gate' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'README and development docs link the Phase 2 plan and command',
	connectlibrary_phase2_gate_has_terms( $readme, array( 'phase-2-privacy-security-accessibility-i18n-test-plan.md', 'composer phase2:quality-gate' ) )
	&& connectlibrary_phase2_gate_has_terms( $development, array( 'phase-2-privacy-security-accessibility-i18n-test-plan.md', 'composer phase2:quality-gate' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Accessibility/i18n checklist links the Phase 2 gate without removing Phase 1 guidance',
	connectlibrary_phase2_gate_has_terms( $a11y_i18n, array( 'Phase 2', 'composer phase2:quality-gate', 'Out of scope (Phase 1)' ) )
);
$checks[] = connectlibrary_phase2_gate_report(
	'Runtime code contains high-signal WordPress security patterns',
	connectlibrary_phase2_gate_has_terms( $includes, array( 'current_user_can', 'permission_callback', 'register_rest_route', 'esc_html', 'sanitize_' ) )
);

if ( in_array( false, $checks, true ) ) {
	exit( 1 );
}

exit( 0 );
