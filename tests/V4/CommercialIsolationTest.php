<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Static guard for the commercial V4 files (everything under src/V4 except
 * the free entry point and Insights/): they may use the Insights parts, but
 * never the consent notice or the free entry point — commercial consent is
 * implied, so nothing may ever ask — and the old `/ping` is gone for good.
 */
class CommercialIsolationTest extends PHPUnitTestCase {

	/**
	 * @return string[] Absolute paths of the commercial V4 files.
	 */
	private function files(): array {
		$src   = dirname( __DIR__, 2 ) . '/src/V4';
		$files = array_merge(
			array_diff( glob( $src . '/*.php' ), [ $src . '/Insights.php' ] ),
			glob( $src . '/Admin/*.php' ),
			glob( $src . '/License/*.php' )
		);

		$this->assertCount( 8, $files, 'Commercial V4 files not found.' );

		return $files;
	}

	/** @test */
	public function never_reference_the_consent_notice_or_the_free_entry_point() {
		$forbidden = '/Insights\\\\Notice\b|new\s+\\\\?(?:Shazzad\\\\PluginUpdater\\\\V4\\\\)?(?:Insights|Notice)\s*\(|\bV4\\\\Insights\s*;/';

		foreach ( $this->files() as $file ) {
			$code = file_get_contents( $file );

			$this->assertSame( 0, preg_match( $forbidden, $code, $match ), basename( $file ) . ' references ' . ( $match[0] ?? '' ) );
		}
	}

	/** @test */
	public function never_ping_and_never_reach_into_older_namespaces() {
		foreach ( $this->files() as $file ) {
			$code = file_get_contents( $file );

			$this->assertStringNotContainsString( '->ping(', $code, basename( $file ) );
			$this->assertStringNotContainsString( 'function ping(', $code, basename( $file ) );
			$this->assertSame( 0, preg_match( '/PluginUpdater\\\\V[12]\\\\|PluginUpdater\\\\(Integration|Client|Updater|Tracker|Admin)\b/', $code, $match ), basename( $file ) . ' references ' . ( $match[0] ?? '' ) );
		}
	}

	/** @test */
	public function insights_imports_are_limited_to_the_commercial_parts() {
		foreach ( $this->files() as $file ) {
			preg_match_all( '/^use\s+([^;]+);/m', file_get_contents( $file ), $matches );

			foreach ( $matches[1] as $import ) {
				$this->assertMatchesRegularExpression(
					'/^(WP_Error|Shazzad\\\\PluginUpdater\\\\V4\\\\(Integration|Insights\\\\(Client|Collector|Consent|Scheduler)(\s+as\s+\w+)?))$/',
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
