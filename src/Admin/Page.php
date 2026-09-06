<?php
declare(strict_types=1);

namespace CB\Backups\Admin;

use CB\Backups\Jobs\Repository;
use CB\Backups\Restore\ArchiveValidator;
use CB\Backups\Restore\MigrationPlan;
use CB\Backups\Schedule\Scheduler;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Capabilities;
use CB\Backups\Support\SiteIdentity;
use CB\Backups\Support\Telemetry;
use CB\Core\Admin\Page as PageContract;
use CB\Core\UI\Notice;
use CB\Core\UI\StateBadge;

defined( 'ABSPATH' ) || exit;

final class Page implements PageContract {
	public function slug(): string {
		return 'core-blueprint-backups';
	}

	public function title(): string {
		return __( 'Backups', 'core-blueprint-backups' );
	}

	public function menu_title(): string {
		return __( 'Backups', 'core-blueprint-backups' );
	}

	public function capability(): string {
		return Capabilities::MANAGE;
	}

	public function position(): ?int {
		return 105;
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to manage backups.', 'core-blueprint-backups' ) );
		}
		LocalStorage::ensure();
		$tab = sanitize_key( (string) ( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : 'backups' ) );
		if ( ! in_array( $tab, [ 'backups', 'restore', 'schedules' ], true ) ) {
			$tab = 'backups';
		}
		echo '<div class="wrap cb-core-wrap cb-backups">';
		echo '<h1 class="cb-core-title">' . esc_html__( 'Backups', 'core-blueprint-backups' ) . '</h1>';
		echo '<p class="cb-core-intro">' . esc_html__( 'Create backup', 'core-blueprint-backups' ) . ' · ' . esc_html__( 'Import & Restore', 'core-blueprint-backups' ) . ' · ' . esc_html__( 'Schedules', 'core-blueprint-backups' ) . '</p>';
		$this->tabs( $tab );
		$this->job_progress();
		switch ( $tab ) {
			case 'restore':
				$this->restore_tab();
				break;
			case 'schedules':
				$this->schedules_tab();
				break;
			default:
				$this->backups_tab();
		}
		echo '</div>';
	}

	private function tabs( string $active ): void {
		$tabs = [
			'backups'   => __( 'Backups', 'core-blueprint-backups' ),
			'restore'   => __( 'Import & Restore', 'core-blueprint-backups' ),
			'schedules' => __( 'Schedules', 'core-blueprint-backups' ),
		];
		echo '<nav class="nav-tab-wrapper cb-core-tab-wrapper cb-backups-tabs">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg( [ 'page' => $this->slug(), 'tab' => $slug ], admin_url( 'admin.php' ) );
			$class = 'nav-tab' . ( $active === $slug ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private function backups_tab(): void {
		$backups = LocalStorage::list_backups();
		echo '<div class="cb-backups-grid">';
		$this->create_card( 'database', __( 'Database backup', 'core-blueprint-backups' ), __( 'Exports all WordPress tables for this site into a portable Core Blueprint backup. The SQL can also be downloaded separately.', 'core-blueprint-backups' ) );
		$this->create_card( 'website', __( 'Full website backup', 'core-blueprint-backups' ), __( 'Backs up the database and wp-content, including plugins, themes, uploads, MU plugins, languages and custom content files.', 'core-blueprint-backups' ) );
		echo '</div>';

		echo '<section class="cb-core-section cb-backups-section">';
		echo '<h2 class="cb-core-section-title">' . esc_html__( 'Available backups', 'core-blueprint-backups' ) . '</h2>';
		if ( ! $backups ) {
			echo '<p>' . esc_html__( 'No backups have been created yet.', 'core-blueprint-backups' ) . '</p>';
			echo '</section>';
			return;
		}
		$this->backup_table( $backups, false );
		echo '<p>' . esc_html__( 'Archive verified means the stored files passed checksum checks. Before a restore replaces live data, its database contents are also compared with the recorded source values. A successful restore rehearsal is a separate check.', 'core-blueprint-backups' ) . '</p>';
		echo '</section>';
	}

	private function create_card( string $type, string $title, string $description ): void {
		echo '<section class="cb-core-panel cb-backups-card">';
		echo '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cb_backups_start">';
		echo '<input type="hidden" name="backup_type" value="' . esc_attr( $type ) . '">';
		wp_nonce_field( 'cb_backups_start' );
		submit_button( __( 'Create backup', 'core-blueprint-backups' ), 'primary', 'submit', false );
		echo '</form></section>';
	}

	private function restore_tab(): void {
		echo Notice::render( [
			'variant' => Notice::WARNING,
			'title'   => __( 'Restore replaces live site data.', 'core-blueprint-backups' ),
			'message' => __( 'Backup format v1 only restores to the same site URL and table prefix. Migration and URL replacement are intentionally blocked. Core Blueprint Base and Backups code remain at their currently installed versions during recovery so the restore engine cannot replace itself mid-operation.', 'core-blueprint-backups' ),
			'class'   => 'cb-backups-restore-warning',
		] );

		echo '<section class="cb-core-panel cb-backups-wide-card"><h2>' . esc_html__( 'Import a backup', 'core-blueprint-backups' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload a .cbbackup file in resumable chunks. Uploading only prepares the archive; restoring live data is a separate confirmed action.', 'core-blueprint-backups' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cb-chunk-upload="1" id="cb-backups-import-form">';
		echo '<input type="hidden" name="action" value="cb_backups_upload_restore">';
		wp_nonce_field( 'cb_backups_upload_restore' );
		echo '<div class="cb-backups-import-controls"><input type="file" name="backup_file" id="cb-backups-import-file" accept=".cbbackup" required> ';
		echo '<button type="submit" class="button button-primary" id="cb-backups-import-start">' . esc_html__( 'Upload backup', 'core-blueprint-backups' ) . '</button></div>';
		echo '<p class="description">' . esc_html__( 'Large files are uploaded in resumable chunks. If this page is closed, select the same file again later and the upload resumes from the last committed byte.', 'core-blueprint-backups' ) . '</p>';
		echo '<div class="cb-backups-import-progress" id="cb-backups-import-progress" hidden>';
		echo '<div class="cb-backups-import-heading"><strong id="cb-backups-import-status">' . esc_html__( 'Preparing upload…', 'core-blueprint-backups' ) . '</strong><span id="cb-backups-import-percent">0%</span></div>';
		echo '<div class="cb-backups-progress"><span id="cb-backups-import-bar" style="width:0%"></span></div>';
		echo '<p><span id="cb-backups-import-bytes">0 B</span> <span class="cb-backups-import-filename" id="cb-backups-import-filename"></span></p>';
		echo '<button type="button" class="button cb-core-button cb-core-button--warning" id="cb-backups-import-cancel">' . esc_html__( 'Cancel import', 'core-blueprint-backups' ) . '</button>';
		echo '</div></form></section>';

		$imports = LocalStorage::list_imports();
		echo '<section class="cb-core-section cb-backups-section">';
		echo '<h2 class="cb-core-section-title">' . esc_html__( 'Prepared imports', 'core-blueprint-backups' ) . '</h2>';
		echo '<p class="description cb-backups-section-description">' . esc_html__( 'Prepared imports are temporary staging copies. Review the source and destination identity before restoring. Inactive imports are removed automatically after 24 hours.', 'core-blueprint-backups' ) . '</p>';
		if ( ! $imports ) {
			echo '<p>' . esc_html__( 'No imported backups are waiting for restore.', 'core-blueprint-backups' ) . '</p>';
		} else {
			$this->prepared_import_table( $imports );
		}
		echo '</section>';

		$backups = LocalStorage::list_backups();
		echo '<section class="cb-core-section cb-backups-section">';
		echo '<h2 class="cb-core-section-title">' . esc_html__( 'Restore a local backup', 'core-blueprint-backups' ) . '</h2>';
		if ( ! $backups ) {
			echo '<p>' . esc_html__( 'No local backups are available.', 'core-blueprint-backups' ) . '</p>';
			echo '</section>';
			return;
		}
		$this->backup_table( $backups, true );
		echo '</section>';
	}

	/** @param array<int,array<string,mixed>> $imports */
	private function prepared_import_table( array $imports ): void {
		$paths = [];
		foreach ( $imports as $import ) {
			$name = sanitize_file_name( (string) ( $import['archive_name'] ?? '' ) );
			if ( '' !== $name ) $paths[] = LocalStorage::import_path( $name );
		}
		$history_available = true;
		try {
			$latest_jobs = Repository::latest_restores_for_archives( $paths );
		} catch ( \RuntimeException $e ) {
			$latest_jobs = [];
			$history_available = false;
			echo '<p class="cb-backups-error" role="alert">' . esc_html__( 'Could not load restore history. Refresh this page before trying again.', 'core-blueprint-backups' ) . '</p>';
		}
		echo '<div class="cb-core-panel cb-core-panel--table cb-backups-table-scroll">';
		echo '<table class="widefat striped cb-backups-import-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Imported', 'core-blueprint-backups' ) . '</th>';
		echo '<th>' . esc_html__( 'Original file', 'core-blueprint-backups' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'core-blueprint-backups' ) . '</th>';
		echo '<th>' . esc_html__( 'Size', 'core-blueprint-backups' ) . '</th>';
		echo '<th>' . esc_html__( 'Source → Destination', 'core-blueprint-backups' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'core-blueprint-backups' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'core-blueprint-backups' ) . '</th></tr></thead><tbody>';
		foreach ( $imports as $import ) {
			$name = sanitize_file_name( (string) ( $import['archive_name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$created = (int) ( $import['created_timestamp'] ?? $import['modified_at'] ?? 0 );
			$type = 'website' === (string) ( $import['backup_type'] ?? '' ) ? __( 'Full website', 'core-blueprint-backups' ) : __( 'Database', 'core-blueprint-backups' );
			$preflight = $this->import_preflight( $import, $name );
			$preflight_error = (string) ( $preflight['error'] ?? '' );
			$is_migration = '' === $preflight_error && ! empty( $preflight['requires_migration'] );
			$job = $latest_jobs[ LocalStorage::import_path( $name ) ] ?? null;
			$job_active = $job && in_array( (string) $job['status'], [ 'queued', 'running', 'cancelling' ], true );
			$job_terminal = $job && in_array( (string) $job['status'], [ 'completed', 'failed', 'cancelled' ], true );
			echo '<tr><td>' . esc_html( $created > 0 ? wp_date( 'Y-m-d H:i:s', $created ) : '—' ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $import['original_name'] ?? $name ) ) . '</code></td>';
			echo '<td>' . esc_html( $type ) . '</td><td>' . esc_html( size_format( (int) ( $import['size'] ?? 0 ) ) ) . '</td>';
			echo '<td>';
			if ( '' !== $preflight_error ) {
				echo '<strong>' . esc_html__( 'Preflight unavailable', 'core-blueprint-backups' ) . '</strong><br><small>' . esc_html( $preflight_error ) . '</small>';
			} else {
				echo '<strong>' . esc_html__( 'Source', 'core-blueprint-backups' ) . '</strong><br><code>' . esc_html( (string) $preflight['source_site_url'] ) . '</code><br><small>' . esc_html__( 'Table prefix:', 'core-blueprint-backups' ) . ' <code>' . esc_html( (string) $preflight['source_prefix'] ) . '</code></small>';
				echo '<br><span aria-hidden="true">↓</span><br>';
				echo '<strong>' . esc_html__( 'Destination', 'core-blueprint-backups' ) . '</strong><br><code>' . esc_html( (string) $preflight['target_site_url'] ) . '</code><br><small>' . esc_html__( 'Table prefix:', 'core-blueprint-backups' ) . ' <code>' . esc_html( (string) $preflight['target_prefix'] ) . '</code></small>';
			}
			echo '</td>';
			if ( ! $history_available ) {
				echo '<td>' . StateBadge::render( __( 'Status unavailable', 'core-blueprint-backups' ), [ 'variant' => StateBadge::ERROR ] ) . '</td><td><div class="cb-backups-actions">';
			} elseif ( $job ) {
				echo '<td>' . $this->import_execution_status( $job ) . '</td><td><div class="cb-backups-actions">';
			} elseif ( '' !== $preflight_error ) {
				echo '<td>' . StateBadge::render( __( 'Needs review', 'core-blueprint-backups' ), [ 'variant' => StateBadge::ERROR ] ) . '</td><td><div class="cb-backups-actions">';
			} elseif ( $is_migration ) {
				echo '<td>' . StateBadge::render( __( 'Migration ready', 'core-blueprint-backups' ), [ 'variant' => StateBadge::SUCCESS ] ) . '</td><td><div class="cb-backups-actions">';
			} else {
				echo '<td>' . StateBadge::render( __( 'Restore ready', 'core-blueprint-backups' ), [ 'variant' => StateBadge::SUCCESS ] ) . '</td><td><div class="cb-backups-actions">';
			}

			if ( $job ) {
				$result_url = add_query_arg( [ 'page' => $this->slug(), 'tab' => 'restore', 'job' => (string) $job['job_id'] ], admin_url( 'admin.php' ) );
				echo '<a class="button button-primary cb-core-button cb-core-button--primary" href="' . esc_url( $result_url ) . '">' . esc_html( $job_active ? __( 'View progress', 'core-blueprint-backups' ) : __( 'View result', 'core-blueprint-backups' ) ) . '</a>';
			}

			if ( $history_available && '' === $preflight_error && ( ! $job || $job_terminal ) ) {
				if ( $is_migration ) {
					$restore_body = sprintf(
						/* translators: 1: imported backup filename, 2: source URL, 3: source prefix, 4: destination URL, 5: destination prefix. */
						__( 'Migrate %1$s from %2$s (prefix %3$s) to %4$s (prefix %5$s)? The archive will be fully verified first. Core Blueprint will prepare a private migration copy, map database tables to the destination prefix, replace source URLs in serialization-aware data where required, preserve post GUID values, and only then replace live site data.', 'core-blueprint-backups' ),
						(string) ( $import['original_name'] ?? $name ),
						(string) $preflight['source_site_url'],
						(string) $preflight['source_prefix'],
						(string) $preflight['target_site_url'],
						(string) $preflight['target_prefix']
					);
					$modal_title = __( 'Migrate imported backup?', 'core-blueprint-backups' );
					$confirm_label = __( 'Migrate & restore', 'core-blueprint-backups' );
					$button_label = __( 'Migrate & restore', 'core-blueprint-backups' );
				} else {
					$restore_body = sprintf(
						/* translators: 1: imported backup filename, 2: destination URL, 3: destination prefix. */
						__( 'Restore %1$s to %2$s (prefix %3$s)? The source and destination identity match. The archive will be fully verified before replacing the destination data covered by this backup.', 'core-blueprint-backups' ),
						(string) ( $import['original_name'] ?? $name ),
						(string) $preflight['target_site_url'],
						(string) $preflight['target_prefix']
					);
					$modal_title = __( 'Restore imported backup?', 'core-blueprint-backups' );
					$confirm_label = __( 'Restore backup', 'core-blueprint-backups' );
					$button_label = __( 'Restore', 'core-blueprint-backups' );
				}
				if ( $job_terminal ) {
					$button_label = $is_migration ? __( 'Migrate again', 'core-blueprint-backups' ) : __( 'Restore again', 'core-blueprint-backups' );
					$confirm_label = $button_label;
				}
				$button_variant = $job_terminal ? 'secondary' : 'warning';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cb-modal-confirm="1" data-cb-modal-title="' . esc_attr( $modal_title ) . '" data-cb-modal-body="' . esc_attr( $restore_body ) . '" data-cb-modal-confirm-label="' . esc_attr( $confirm_label ) . '" data-cb-modal-variant="danger">';
				echo '<input type="hidden" name="action" value="cb_backups_restore_import"><input type="hidden" name="archive" value="' . esc_attr( $name ) . '">';
				wp_nonce_field( 'cb_backups_restore_import_' . $name );
				$this->restore_acknowledgement( (string) ( $import['backup_type'] ?? 'database' ), (string) $preflight['target_site_url'] );
				echo '<button type="submit" class="button cb-core-button cb-core-button--' . esc_attr( $button_variant ) . '">' . esc_html( $button_label ) . '</button></form>';
			}

			if ( $history_available && ( ! $job || $job_terminal ) ) {
				$delete_body = sprintf(
					/* translators: %s: imported backup filename. */
					__( 'Delete the prepared import %s? The uploaded archive will be removed from private import storage.', 'core-blueprint-backups' ),
					(string) ( $import['original_name'] ?? $name )
				);
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cb-modal-confirm="1" data-cb-modal-title="' . esc_attr__( 'Delete prepared import?', 'core-blueprint-backups' ) . '" data-cb-modal-body="' . esc_attr( $delete_body ) . '" data-cb-modal-confirm-label="' . esc_attr__( 'Delete import', 'core-blueprint-backups' ) . '" data-cb-modal-variant="danger">';
				echo '<input type="hidden" name="action" value="cb_backups_delete_import"><input type="hidden" name="archive" value="' . esc_attr( $name ) . '">';
				wp_nonce_field( 'cb_backups_delete_import_' . $name );
				echo '<button type="submit" class="button cb-core-button cb-core-button--danger">' . esc_html__( 'Delete', 'core-blueprint-backups' ) . '</button></form>';
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/** @param array<string,mixed> $job */
	private function import_execution_status( array $job ): string {
		$migration = 'migration' === ( $job['restore_mode'] ?? '' );
		$status = (string) ( $job['status'] ?? '' );
		$variant = StateBadge::NEUTRAL;
		$date_label = __( 'Last attempt: %s', 'core-blueprint-backups' );
		$date = (string) ( $job['completed_at'] ?? '' );
		switch ( $status ) {
			case 'completed':
				$label = $migration ? __( 'Migrated successfully', 'core-blueprint-backups' ) : __( 'Restored successfully', 'core-blueprint-backups' );
				$date_label = $migration ? __( 'Last migrated: %s', 'core-blueprint-backups' ) : __( 'Last restored: %s', 'core-blueprint-backups' );
				$variant = StateBadge::SUCCESS;
				break;
			case 'failed':
				$label = $migration ? __( 'Migration failed', 'core-blueprint-backups' ) : __( 'Restore failed', 'core-blueprint-backups' );
				$variant = StateBadge::ERROR;
				break;
			case 'cancelled':
				$label = $migration ? __( 'Migration cancelled', 'core-blueprint-backups' ) : __( 'Restore cancelled', 'core-blueprint-backups' );
				break;
			case 'queued':
				$label = $migration ? __( 'Migration queued', 'core-blueprint-backups' ) : __( 'Restore queued', 'core-blueprint-backups' );
				$variant = StateBadge::INFO;
				break;
			case 'running':
				$label = $migration ? __( 'Migrating…', 'core-blueprint-backups' ) : __( 'Restoring…', 'core-blueprint-backups' );
				$label .= ' ' . max( 0, min( 100, (int) ( $job['progress'] ?? 0 ) ) ) . '%';
				$variant = StateBadge::INFO;
				break;
			case 'cancelling':
				$label = __( 'Cancelling…', 'core-blueprint-backups' );
				$variant = StateBadge::WARNING;
				break;
			default:
				$label = __( 'Status unavailable', 'core-blueprint-backups' );
				$variant = StateBadge::ERROR;
				$date = '';
		}
		$html = StateBadge::render( $label, [ 'variant' => $variant ] );
		// Repository timestamps are UTC; wp_date applies the site's configured timezone.
		$timestamp = '' !== $date ? strtotime( $date . ' UTC' ) : false;
		if ( false !== $timestamp ) {
			$html .= '<br><small>' . esc_html( sprintf( $date_label, wp_date( 'Y-m-d H:i:s', $timestamp ) ) ) . '</small>';
		}
		return $html;
	}

	/** @param array<string,mixed> $import @return array<string,mixed> */
	private function import_preflight( array $import, string $archive_name ): array {
		global $wpdb;
		$source_home = untrailingslashit( (string) ( $import['source_home_url'] ?? '' ) );
		$source_site = untrailingslashit( (string) ( $import['source_site_url'] ?? $import['site_url'] ?? '' ) );
		$source_prefix = (string) ( $import['source_prefix'] ?? '' );
		$identity = \CB\Backups\Support\SiteIdentity::current();
		$target_home = untrailingslashit( $identity['home_url'] );
		$target_site = untrailingslashit( $identity['site_url'] );
		$target_prefix = (string) $wpdb->prefix;

		if ( '' !== $source_site && '' !== $source_prefix ) {
			if ( '' === $source_home ) {
				$source_home = $source_site;
			}
			return [
				'requires_migration' => $source_home !== $target_home || $source_site !== $target_site || $source_prefix !== $target_prefix,
				'source_home_url'    => $source_home,
				'source_site_url'    => $source_site,
				'source_prefix'      => $source_prefix,
				'target_home_url'    => $target_home,
				'target_site_url'    => $target_site,
				'target_prefix'      => $target_prefix,
			];
		}

		try {
			$archive = LocalStorage::import_path( $archive_name );
			$manifest = ArchiveValidator::validate( $archive, false );
			return MigrationPlan::build( $manifest );
		} catch ( \Throwable $e ) {
			return [ 'error' => $e->getMessage() ];
		}
	}

	private function schedules_tab(): void {
		$schedules = Scheduler::all();
		$health = Scheduler::health();
		$status = (string) ( $health['status'] ?? 'idle' );
		if ( in_array( $status, [ 'warning', 'critical' ], true ) ) {
			echo Notice::render( [
				'variant' => Notice::WARNING,
				'message' => __( 'Automatic backup schedules are enabled, but the scheduler heartbeat is not recent. Configure a server cron running “cb backup run-due” when WP-Cron is disabled or site traffic is too low.', 'core-blueprint-backups' ),
			] );
		}

		echo '<section class="cb-core-panel"><h2>' . esc_html__( 'Scheduler health', 'core-blueprint-backups' ) . '</h2>';
		echo '<table class="widefat cb-core-kv"><tbody>';
		$last_tick = (int) ( $health['last_tick'] ?? 0 );
		$next_global = (int) ( $health['next_run'] ?? 0 );
		$health_label = match ( $status ) {
			'healthy' => __( 'Healthy', 'core-blueprint-backups' ),
			'warning' => __( 'Heartbeat delayed', 'core-blueprint-backups' ),
			'critical' => __( 'Scheduler not running', 'core-blueprint-backups' ),
			default => __( 'Idle — no automatic schedules enabled', 'core-blueprint-backups' ),
		};
		echo '<tr><th scope="row">' . esc_html__( 'Status', 'core-blueprint-backups' ) . '</th><td><strong>' . esc_html( $health_label ) . '</strong></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Last scheduler tick', 'core-blueprint-backups' ) . '</th><td>' . ( $last_tick > 0 ? esc_html( wp_date( 'Y-m-d H:i:s', $last_tick ) . ' · ' . (string) ( $health['last_tick_source'] ?? '' ) ) : '&mdash;' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Next automatic backup', 'core-blueprint-backups' ) . '</th><td>' . ( $next_global > 0 ? esc_html( wp_date( 'Y-m-d H:i', $next_global ) ) : '&mdash;' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Execution', 'core-blueprint-backups' ) . '</th><td>' . esc_html( ! empty( $health['wp_cron_disabled'] ) ? __( 'WP-Cron disabled — use server cron / CLI for schedule triggering.', 'core-blueprint-backups' ) : __( 'WP-Cron enabled; server cron / CLI may also call the same scheduler safely.', 'core-blueprint-backups' ) ) . '</td></tr>';
		if ( '' !== (string) ( $health['last_error'] ?? '' ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Last scheduler error', 'core-blueprint-backups' ) . '</th><td><code>' . esc_html( (string) $health['last_error'] ) . '</code></td></tr>';
		}
		echo '</tbody></table></section>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cb_backups_save_schedules">';
		wp_nonce_field( 'cb_backups_save_schedules' );
		echo '<div class="cb-core-panel cb-core-panel--table cb-backups-table-scroll">';
		echo '<table class="widefat striped cb-backups-schedules"><thead><tr><th>' . esc_html__( 'Backup', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Enabled', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Frequency', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Weekday', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Time', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Keep automatic', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Last success', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Next run', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'State', 'core-blueprint-backups' ) . '</th></tr></thead><tbody>';
		$weekdays = [ 1 => __( 'Monday', 'core-blueprint-backups' ), 2 => __( 'Tuesday', 'core-blueprint-backups' ), 3 => __( 'Wednesday', 'core-blueprint-backups' ), 4 => __( 'Thursday', 'core-blueprint-backups' ), 5 => __( 'Friday', 'core-blueprint-backups' ), 6 => __( 'Saturday', 'core-blueprint-backups' ), 7 => __( 'Sunday', 'core-blueprint-backups' ) ];
		foreach ( [ 'database' => __( 'Database', 'core-blueprint-backups' ), 'website' => __( 'Full website', 'core-blueprint-backups' ) ] as $type => $label ) {
			$row = $schedules[ $type ];
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td><label><input type="checkbox" name="schedules[' . esc_attr( $type ) . '][enabled]" value="1" ' . checked( ! empty( $row['enabled'] ), true, false ) . '> ' . esc_html__( 'On', 'core-blueprint-backups' ) . '</label></td>';
			echo '<td><select name="schedules[' . esc_attr( $type ) . '][frequency]">';
			foreach ( [ 'hourly' => __( 'Hourly', 'core-blueprint-backups' ), 'daily' => __( 'Daily', 'core-blueprint-backups' ), 'weekly' => __( 'Weekly', 'core-blueprint-backups' ) ] as $value => $frequency_label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $row['frequency'], $value, false ) . '>' . esc_html( $frequency_label ) . '</option>';
			}
			echo '</select></td>';
			echo '<td><select name="schedules[' . esc_attr( $type ) . '][weekday]">';
			foreach ( $weekdays as $day => $day_label ) {
				echo '<option value="' . esc_attr( (string) $day ) . '" ' . selected( (int) ( $row['weekday'] ?? 1 ), $day, false ) . '>' . esc_html( $day_label ) . '</option>';
			}
			echo '</select></td>';
			echo '<td><div class="cb-core-time-picker" data-cb-time-picker>';
			echo '<input type="text" inputmode="numeric" autocomplete="off" placeholder="HH:MM" aria-label="' . esc_attr__( 'Time in 24-hour format', 'core-blueprint-backups' ) . '" name="schedules[' . esc_attr( $type ) . '][time]" value="' . esc_attr( (string) $row['time'] ) . '">';
			echo '<button type="button" class="button" data-cb-time-picker-toggle aria-label="' . esc_attr__( 'Choose time', 'core-blueprint-backups' ) . '"></button>';
			echo '</div></td>';
			echo '<td><input class="small-text" type="number" min="1" max="100" name="schedules[' . esc_attr( $type ) . '][keep]" value="' . esc_attr( (string) $row['keep'] ) . '"></td>';
			$last_success = (int) ( $row['last_success'] ?? 0 );
			$next = (int) ( $row['next_run'] ?? 0 );
			echo '<td>' . ( $last_success > 0 ? esc_html( wp_date( 'Y-m-d H:i', $last_success ) ) : '&mdash;' ) . '</td>';
			echo '<td>' . ( $next > 0 ? esc_html( wp_date( 'Y-m-d H:i', $next ) ) : '&mdash;' ) . '</td>';
			$state_label = __( 'Off', 'core-blueprint-backups' );
			if ( ! empty( $row['enabled'] ) ) {
				if ( (int) ( $row['delayed_since'] ?? 0 ) > 0 ) {
					$state_label = __( 'Delayed', 'core-blueprint-backups' );
				} elseif ( (int) ( $row['last_failure'] ?? 0 ) > (int) ( $row['last_success'] ?? 0 ) ) {
					$state_label = __( 'Retry scheduled', 'core-blueprint-backups' );
				} elseif ( $last_success > 0 ) {
					$state_label = __( 'Healthy', 'core-blueprint-backups' );
				} else {
					$state_label = __( 'Waiting for first run', 'core-blueprint-backups' );
				}
			}
			echo '<td><strong>' . esc_html( $state_label ) . '</strong>';
			if ( '' !== (string) ( $row['last_error'] ?? '' ) ) {
				echo '<br><small>' . esc_html( (string) $row['last_error'] ) . '</small>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
		submit_button( __( 'Save schedules', 'core-blueprint-backups' ) );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Retention only manages automatic backups created by these schedules; manual restore points are never deleted automatically. For Hourly schedules, the minute from Time is used. Weekday is used only for Weekly schedules. Schedules remain local and work without Beacon or Hub.', 'core-blueprint-backups' ) . '</p>';
	}

	/** @param array<int,array<string,mixed>> $backups */
	private function backup_table( array $backups, bool $restore_mode ): void {
		$bulk_form_id = 'cb-backups-bulk-delete';
		if ( ! $restore_mode ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="' . esc_attr( $bulk_form_id ) . '" class="cb-backups-bulk-actions" data-cb-modal-confirm="1" data-cb-backups-bulk-delete="1" data-cb-modal-title="' . esc_attr__( 'Delete selected backups?', 'core-blueprint-backups' ) . '" data-cb-modal-body="' . esc_attr__( 'Select one or more backups to delete.', 'core-blueprint-backups' ) . '" data-cb-modal-confirm-label="' . esc_attr__( 'Delete selected', 'core-blueprint-backups' ) . '" data-cb-modal-variant="danger">';
			echo '<input type="hidden" name="action" value="cb_backups_bulk_delete">';
			wp_nonce_field( 'cb_backups_bulk_delete' );
			echo '<button type="submit" class="button cb-core-button cb-core-button--danger" data-cb-backups-bulk-submit disabled>' . esc_html__( 'Delete selected', 'core-blueprint-backups' ) . '</button>';
			echo '<span class="cb-backups-selection-count" data-cb-backups-selection-count aria-live="polite"></span>';
			echo '</form>';
		}

		echo '<div class="cb-core-panel cb-core-panel--table cb-backups-table-scroll">';
		echo '<table class="widefat striped cb-backups-table"><thead><tr>';
		if ( ! $restore_mode ) {
			echo '<td class="check-column"><input type="checkbox" data-cb-backups-select-all aria-label="' . esc_attr__( 'Select all backups', 'core-blueprint-backups' ) . '"></td>';
		}
		echo '<th>' . esc_html__( 'Created', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Type', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Size', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Rows', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Files', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Duration', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Integrity', 'core-blueprint-backups' ) . '</th><th>' . esc_html__( 'Actions', 'core-blueprint-backups' ) . '</th></tr></thead><tbody>';
		foreach ( $backups as $backup ) {
			$name     = (string) ( $backup['archive_name'] ?? '' );
			$type     = (string) ( $backup['backup_type'] ?? 'unknown' );
			$created  = (int) ( $backup['created_timestamp'] ?? $backup['modified_at'] ?? 0 );
			$size     = (int) ( $backup['size'] ?? 0 );
			$rows     = (int) ( $backup['database_rows'] ?? 0 );
			$files    = (int) ( $backup['files'] ?? 0 );
			$duration = (int) ( $backup['duration_seconds'] ?? 0 );
			echo '<tr>';
			if ( ! $restore_mode ) {
				echo '<th scope="row" class="check-column"><input type="checkbox" name="archives[]" value="' . esc_attr( $name ) . '" form="' . esc_attr( $bulk_form_id ) . '" data-cb-backups-select aria-label="' . esc_attr( sprintf( __( 'Select backup created %s', 'core-blueprint-backups' ), $created > 0 ? wp_date( 'Y-m-d H:i:s', $created ) : $name ) ) . '"></th>';
			}
			echo '<td>' . esc_html( $created > 0 ? wp_date( 'Y-m-d H:i:s', $created ) : '—' ) . '</td><td>' . esc_html( 'website' === $type ? __( 'Full website', 'core-blueprint-backups' ) : __( 'Database', 'core-blueprint-backups' ) ) . '</td><td>' . esc_html( size_format( $size ) ) . '</td><td>' . esc_html( $rows > 0 ? number_format_i18n( $rows ) : '—' ) . '</td><td>' . esc_html( 'website' === $type ? number_format_i18n( $files ) : '—' ) . '</td><td>' . esc_html( $duration > 0 ? Telemetry::format_duration( $duration ) : '—' ) . '</td><td>' . ( ! empty( $backup['verified'] ) ? '<span class="cb-backups-ok">✓ ' . esc_html__( 'Archive verified', 'core-blueprint-backups' ) . '</span>' : esc_html__( 'Not verified', 'core-blueprint-backups' ) ) . '</td><td><div class="cb-backups-actions">';
			if ( $restore_mode ) {
				$restore_body = sprintf(
					/* translators: %s: backup archive filename. */
					__( 'Restore %s? The archive will be verified before the restore starts. Live site data will be replaced and the site may be temporarily unavailable.', 'core-blueprint-backups' ),
					$name
				);
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cb-modal-confirm="1" data-cb-modal-title="' . esc_attr__( 'Restore backup?', 'core-blueprint-backups' ) . '" data-cb-modal-body="' . esc_attr( $restore_body ) . '" data-cb-modal-confirm-label="' . esc_attr__( 'Restore backup', 'core-blueprint-backups' ) . '" data-cb-modal-variant="danger"><input type="hidden" name="action" value="cb_backups_restore"><input type="hidden" name="archive" value="' . esc_attr( $name ) . '">';
				wp_nonce_field( 'cb_backups_restore_' . $name );
				$this->restore_acknowledgement( $type, SiteIdentity::current()['site_url'] );
				echo '<button type="submit" class="button cb-core-button cb-core-button--warning">' . esc_html__( 'Restore', 'core-blueprint-backups' ) . '</button>';
				echo '</form>';
			} else {
				$download = wp_nonce_url( add_query_arg( [ 'action' => 'cb_backups_download', 'archive' => $name ], admin_url( 'admin-post.php' ) ), 'cb_backups_download_' . $name );
				$sql      = wp_nonce_url( add_query_arg( [ 'action' => 'cb_backups_download', 'archive' => $name, 'part' => 'database' ], admin_url( 'admin-post.php' ) ), 'cb_backups_download_' . $name );
				echo '<a class="button" href="' . esc_url( $download ) . '">' . esc_html__( 'Download', 'core-blueprint-backups' ) . '</a>';
				echo '<a class="button" href="' . esc_url( $sql ) . '">' . esc_html__( 'SQL', 'core-blueprint-backups' ) . '</a>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cb_backups_verify"><input type="hidden" name="archive" value="' . esc_attr( $name ) . '">';
				wp_nonce_field( 'cb_backups_verify_' . $name );
				submit_button( __( 'Verify', 'core-blueprint-backups' ), 'secondary', 'submit', false );
				echo '</form>';
				$delete_body = sprintf(
					/* translators: %s: backup archive filename. */
					__( 'Permanently delete %s? This restore point will be removed from local backup storage and cannot be recovered.', 'core-blueprint-backups' ),
					$name
				);
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cb-modal-confirm="1" data-cb-modal-title="' . esc_attr__( 'Delete backup?', 'core-blueprint-backups' ) . '" data-cb-modal-body="' . esc_attr( $delete_body ) . '" data-cb-modal-confirm-label="' . esc_attr__( 'Delete backup', 'core-blueprint-backups' ) . '" data-cb-modal-variant="danger"><input type="hidden" name="action" value="cb_backups_delete"><input type="hidden" name="archive" value="' . esc_attr( $name ) . '">';
				wp_nonce_field( 'cb_backups_delete_' . $name );
				echo '<button type="submit" class="button cb-core-button cb-core-button--danger">' . esc_html__( 'Delete', 'core-blueprint-backups' ) . '</button>';
				echo '</form>';
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private function restore_acknowledgement( string $type, string $target ): void {
		/* translators: %s: destination website URL whose data will be replaced. */
		$template = 'website' === $type
			? __( 'I understand that this action replaces the current database and site files on %s. Changes on this site that are not in this backup will be lost.', 'core-blueprint-backups' )
			: __( 'I understand that this action replaces the current database on %s. Database changes on this site that are not in this backup will be lost.', 'core-blueprint-backups' );
		echo '<input type="hidden" name="restore_acknowledged" value="" data-cb-restore-acknowledgement="' . esc_attr( sprintf( $template, $target ) ) . '">';
	}

	private function job_progress(): void {
		$job = $this->current_job();
		if ( ! $job ) {
			return;
		}
		$data      = JobPresentation::present( $job, Actions::public_job( $job ) );
		$job_id    = (string) $job['job_id'];
		$is_backup = 'backup' === (string) $job['kind'];
		$is_website = 'website' === (string) $job['backup_type'];
		$status    = (string) $job['status'];
		$active    = in_array( $status, [ 'queued', 'running', 'cancelling' ], true );

		echo '<section class="cb-core-panel cb-backups-job" id="cb-backups-job" data-job-id="' . esc_attr( $job_id ) . '" data-status="' . esc_attr( $status ) . '">';
		if ( ! $active ) {
			$message = (string) $data['stage_label'];
			if ( 'failed' === $status ) {
				$message = __( 'Restore or backup failed. Review the job error below.', 'core-blueprint-backups' );
			} elseif ( 'cancelled' === $status ) {
				$message = __( 'Backup cancelled.', 'core-blueprint-backups' );
			}
			echo Notice::render( [
				'variant' => 'completed' === $status ? Notice::SUCCESS : ( 'failed' === $status ? Notice::ERROR : Notice::WARNING ),
				'message' => $message,
			] );
			if ( 'completed' === $status && 'restore' === $job['kind'] && 'migration' === ( $job['meta']['restore_mode'] ?? '' ) ) {
				echo '<div class="cb-backups-next-steps">';
				echo Notice::render( [
					'variant' => Notice::WARNING,
					'title'   => __( 'Next steps: save permalinks and clear caches', 'core-blueprint-backups' ),
					'message' => __( 'Recommended before using the migrated site:', 'core-blueprint-backups' ),
					'items'   => [
						__( 'Open Settings → Permalinks and click Save Changes without changing your permalink structure.', 'core-blueprint-backups' ),
						__( 'Clear any page-cache plugin, hosting/server and CDN caches you use, then refresh your browser.', 'core-blueprint-backups' ),
					],
				] );
				echo '<p><a class="button button-primary cb-core-button cb-core-button--primary" href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Open permalink settings', 'core-blueprint-backups' ) . '</a></p>';
				echo '</div>';
			}
		}
		echo '<div class="cb-backups-job-heading"><h2>' . esc_html( 'restore' === $job['kind'] ? __( 'Restore progress', 'core-blueprint-backups' ) : __( 'Backup progress', 'core-blueprint-backups' ) ) . '</h2><span id="cb-backups-status" class="cb-backups-status">' . esc_html( ucfirst( $status ) ) . '</span></div>';
		echo '<div class="cb-backups-progress"><span id="cb-backups-progress-bar" style="width:' . esc_attr( (string) $job['progress'] ) . '%"></span></div>';
		echo '<p class="cb-backups-progress-line"><strong id="cb-backups-progress-value">' . esc_html( (string) $job['progress'] ) . '%</strong> · <span id="cb-backups-stage">' . esc_html( (string) $data['stage_label'] ) . '</span></p>';

		echo '<dl class="cb-core-tiles cb-backups-metrics">';
		$this->metric( __( 'Database rows', 'core-blueprint-backups' ), '<span id="cb-backups-rows">' . esc_html( $this->rows_label( $data ) ) . '</span>' );
		$this->metric( __( 'Tables', 'core-blueprint-backups' ), '<span id="cb-backups-tables">' . esc_html( $this->tables_label( $data ) ) . '</span>' );
		if ( $is_website ) {
			$this->metric( (string) $data['file_metric']['label'], '<span id="cb-backups-files">' . esc_html( $this->metric_value( $data['file_metric'] ) ) . '</span>' );
			$this->metric( (string) $data['byte_metric']['label'], '<span id="cb-backups-file-bytes">' . esc_html( $this->metric_value( $data['byte_metric'], true ) ) . '</span>' );
			$this->metric( __( 'Throughput', 'core-blueprint-backups' ), '<span id="cb-backups-throughput">' . esc_html( $this->throughput_label( $data ) ) . '</span>' );
		}
		$this->metric( __( 'Elapsed', 'core-blueprint-backups' ), '<span id="cb-backups-elapsed">' . esc_html( Telemetry::format_duration( (int) $data['elapsed_seconds'] ) ) . '</span>' );
		$this->metric( __( 'Output size', 'core-blueprint-backups' ), '<span id="cb-backups-size">' . esc_html( size_format( (int) $data['output_bytes'] ) ) . '</span>' );
		$typical = is_int( $data['typical_duration_seconds'] ) ? (int) $data['typical_duration_seconds'] : 0;
		$this->metric( __( 'Typical duration', 'core-blueprint-backups' ), '<span id="cb-backups-typical">' . esc_html( $typical > 0 ? Telemetry::format_duration( $typical ) : '—' ) . '</span>' );
		echo '</dl>';

		echo '<p class="cb-backups-current" id="cb-backups-current-wrap"' . ( '' === (string) $data['current_table'] ? ' hidden' : '' ) . '><strong>' . esc_html__( 'Current table:', 'core-blueprint-backups' ) . '</strong> <code id="cb-backups-current-table">' . esc_html( (string) $data['current_table'] ) . '</code> <span id="cb-backups-current-table-rows">' . esc_html( $this->current_table_rows_label( $data ) ) . '</span></p>';
		if ( $is_website ) {
			echo '<p class="cb-backups-current" id="cb-backups-current-file-wrap"' . ( '' === (string) ( $data['current_file'] ?? '' ) ? ' hidden' : '' ) . '><strong>' . esc_html__( 'Current file:', 'core-blueprint-backups' ) . '</strong> <code id="cb-backups-current-file">' . esc_html( (string) ( $data['current_file'] ?? '' ) ) . '</code></p>';
		}
		echo '<div id="cb-backups-long-warning" class="cb-backups-long-warning" hidden>' . Notice::render( [
			'variant' => Notice::WARNING,
			'message' => __( 'This backup is taking significantly longer than its recent baseline. You can inspect the current stage and cancel it safely at the next checkpoint.', 'core-blueprint-backups' ),
		] ) . '</div>';
		echo '<p id="cb-backups-job-error" class="cb-backups-error">' . esc_html( (string) ( $job['error_text'] ?? '' ) ) . '</p>';

		if ( $is_backup ) {
			echo '<div class="cb-backups-job-actions">';
			echo '<button type="button" class="button cb-core-button cb-core-button--warning" id="cb-backups-cancel"' . ( $active ? '' : ' hidden' ) . ( 'cancelling' === $status ? ' disabled' : '' ) . '>' . esc_html( 'cancelling' === $status ? __( 'Cancelling…', 'core-blueprint-backups' ) : __( 'Cancel backup', 'core-blueprint-backups' ) ) . '</button>';
			echo '</div>';
		}
		echo '</section>';
	}

	/** @param array<string,mixed> $data */
	private function rows_label( array $data ): string {
		$done      = (int) ( $data['database_rows_done'] ?? 0 );
		$exact     = (int) ( $data['database_rows_total'] ?? 0 );
		$estimated = (int) ( $data['database_rows_estimated'] ?? 0 );
		if ( $exact > 0 ) {
			return number_format_i18n( $exact );
		}
		if ( $estimated > 0 ) {
			return number_format_i18n( $done ) . ' / ~' . number_format_i18n( $estimated );
		}
		return $done > 0 ? number_format_i18n( $done ) : '—';
	}

	/** @param array<string,mixed> $data */
	private function tables_label( array $data ): string {
		$done  = (int) ( $data['database_tables_done'] ?? 0 );
		$total = (int) ( $data['database_tables_total'] ?? 0 );
		return $total > 0 ? number_format_i18n( $done ) . ' / ' . number_format_i18n( $total ) : '—';
	}

	/** @param array{label:string,done:int,total:int} $metric */
	private function metric_value( array $metric, bool $bytes = false ): string {
		// Use the same phase-specific domains as the polling response. Archive
		// payloads include SQL/metadata and cannot be divided by filesystem totals.
		$done = (int) $metric['done'];
		$total = (int) $metric['total'];
		$format = $bytes ? 'size_format' : 'number_format_i18n';
		if ( $total > 0 ) {
			return $format( $done ) . ' / ' . $format( $total );
		}
		return $done > 0 ? $format( $done ) : '—';
	}


	/** @param array<string,mixed> $data */
	private function throughput_label( array $data ): string {
		$files_per_second = (float) ( $data['package_files_per_second'] ?? 0 );
		$bytes_per_second = (int) ( $data['package_bytes_per_second'] ?? 0 );
		if ( $files_per_second <= 0 && $bytes_per_second <= 0 ) {
			return '—';
		}
		return number_format_i18n( $files_per_second, 1 ) . ' files/s · ' . size_format( $bytes_per_second ) . '/s';
	}

	/** @param array<string,mixed> $data */
	private function current_table_rows_label( array $data ): string {
		$done      = (int) ( $data['current_table_rows_done'] ?? 0 );
		$estimated = (int) ( $data['current_table_rows_estimated'] ?? 0 );
		if ( $estimated > 0 ) {
			return '(' . number_format_i18n( $done ) . ' / ~' . number_format_i18n( $estimated ) . ')';
		}
		return $done > 0 ? '(' . number_format_i18n( $done ) . ')' : '';
	}

	private function metric( string $label, string $value_html ): void {
		echo '<div class="cb-core-tile cb-core-tile--metric cb-core-tile--neutral cb-backups-metric"><dt class="cb-core-tile__label">' . esc_html( $label ) . '</dt><dd class="cb-core-tile__value">' . wp_kses( $value_html, [ 'span' => [ 'id' => true ] ] ) . '</dd></div>';
	}

	/** @return array<string,mixed>|null */
	private function current_job(): ?array {
		$job_id = isset( $_GET['job'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['job'] ) ) : '';
		if ( '' !== $job_id ) {
			$job = Repository::get( $job_id );
			// Keep an explicitly requested result visible after authentication or reload.
			if ( $job ) {
				return $job;
			}
		}

		$active = Repository::active( 1 );
		return $active[0] ?? null;
	}

}
