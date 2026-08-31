<?php
declare(strict_types=1);

namespace CB\Backups\CLI;

use CB\Backups\Schedule\Scheduler;

defined( 'ABSPATH' ) || exit;

final class RunDue {
	public function __invoke( array $args, array $assoc_args ): void {
		Scheduler::run_due( 'cli' );
		\WP_CLI::success( 'Due schedules checked and active backup jobs advanced.' );
	}
}
