<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Static guard: the free-plugin files must never reach update or license
 * code. A wordpress.org plugin ships only src/V4/Insights.php and
 * src/V4/Insights/, so any reference outside V4\Insights would either
 * fatal there or smuggle self-update code past guideline 8.
 */
class InsightsIsolationTest extends PHPUnitTestCase {

	/**
	 * @return string[] Absolute paths of the free-plugin files.
	 */
	private function files(): array {
		$src   = dirname( __DIR__, 2 ) . '/src/V4';
		$files = array_merge( [ $src . '/Insights.php' ], glob( $src . '/Insights/*.php' ) );

		$this->assertGreaterThanOrEqual( 6, count( $files ), 'Insights files not found.' );

		return $files;
	}

	/** @test */
	public function no_reference_to_update_or_license_classes() {
		$forbidden = '/\b(Updater|License|LicensePage|Integration|Tracker|Store|UpdateMessage|Notices)\b|\\\\V[12]\\\\|\\\\Admin\\\\/';

		foreach ( $this->files() as $file ) {
			$code = file_get_contents( $file );

			$this->assertSame( 0, preg_match( $forbidden, $code, $match ), basename( $file ) . ' references ' . ( $match[0] ?? '' ) );
		}
	}

	/** @test */
	public function imports_stay_inside_the_insights_namespace() {
		foreach ( $this->files() as $file ) {
			preg_match_all( '/^use\s+([^;]+);/m', file_get_contents( $file ), $matches );

			foreach ( $matches[1] as $import ) {
				$this->assertMatchesRegularExpression(
					'/^(WP_Error|Shazzad\\\\PluginUpdater\\\\V4\\\\Insights(\\\\(Client|Collector|Consent|Notice|Scheduler))?)$/',
					trim( $import ),
					basename( $file ) . " imports {$import}"
				);
			}
		}
	}

	/** @test */
	public function every_file_keeps_the_abspath_and_class_exists_guards() {
		foreach ( $this->files() as $file ) {
			$code = file_get_contents( $file );

			$this->assertStringContainsString( "if ( ! \\defined( 'ABSPATH' ) ) {", $code, basename( $file ) );
			$this->assertStringContainsString( "if ( ! class_exists( __NAMESPACE__ . '\\\\", $code, basename( $file ) );
		}
	}
}
