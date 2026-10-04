=== Core Blueprint Backups ===
Tags: backup, restore, migration, database, site-management
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Governed database and full-site backups with local restore, migration, scheduling and recovery for Core Blueprint.

== Description ==

Core Blueprint Backups provides local database and full-site backup, verification, restore and single-site migration for WordPress installations using Core Blueprint Base.

Backup and restore jobs are server-owned. They continue independently of the browser and are designed to fail closed when a restore, migration or recovery step cannot be verified.

Features include database backups, full-site backups, local .cbbackup archive verification, import preparation, same-site restore, governed single-site migration, schedules, automatic retention, operational history, Site Health integration and WP-CLI backup commands.

Core Blueprint Base is required and provides the shared governance, admin and migration-recovery foundations used by Backups.

Local backup, restore, migration, scheduling and retention do not require Core Blueprint Beacon or Hub.

Optional remote orchestration is available when the separate Core Blueprint Beacon and Hub components are installed and intentionally configured by the site operator. Beacon authenticates the remote management boundary used by Hub. Remote restore and remote delete are not exposed by the v1 remote API.

Core Blueprint service information:
https://coreblueprint.io/

Terms:
https://coreblueprint.io/terms

Privacy:
https://coreblueprint.io/privacy

The WordPress.org distribution uses WordPress.org as its update authority and does not include the Core Blueprint external update adapter.

Development source and build tooling:
https://github.com/Core-Blueprint/wp-core-blueprint-backups

== Installation ==

1. Install and activate Core Blueprint Base.
2. Install and activate Core Blueprint Backups.
3. Open Core Blueprint > Backups in WordPress Admin.
4. Create a backup or configure a schedule.
5. Verify restore points regularly and perform recovery rehearsals appropriate to your environment.

PHP 8.4 or newer and the PHP ZIP extension are required.

== Frequently Asked Questions ==

= Does Backups require Core Blueprint Base? =

Yes. Core Blueprint Base is a required dependency and supplies the public governance, admin and migration-recovery contracts used by Backups.

= Do I need Core Blueprint Hub or Beacon? =

No. Local backup, verification, restore, migration, scheduling and retention work without Hub or Beacon.

= Where are backups stored? =

By default, Backups uses a private tokenized directory below wp-content and creates server-level protection files in that directory. The storage path can also be explicitly configured by the site operator.

= Are backups deleted when I uninstall the plugin? =

No. Uninstall removes Backups-owned job and schedule state but intentionally preserves backup archives and the private storage token.

= Does Backups support multisite migration? =

No. Version 1.0 targets single-site WordPress installations. Multisite migration is outside the current contract.

= Does local operation send my backup data to Core Blueprint? =

No. Local backup, verification, restore, migration and scheduling execute on the WordPress site.

Optional Beacon/Hub orchestration is used only when an operator separately installs and configures those components.

== Privacy ==

Core Blueprint Backups does not require an external service for local backup, restore, migration or scheduling.

Backup archives may contain site content and personal data because they represent the WordPress installation being protected. Site operators are responsible for protecting stored and downloaded backup archives according to their security, privacy and retention requirements.

Optional Beacon/Hub orchestration is not required. When an operator intentionally configures those separate components, authenticated management metadata may be exchanged as part of that service relationship.

Privacy information:
https://coreblueprint.io/privacy

== Changelog ==

= 1.0.0 =

* First stable WordPress.org submission release.
* Database and full-site backup creation.
* Verified .cbbackup archives.
* Same-site restore and governed single-site migration.
* Base-owned secure migration recovery.
* Scheduling and automatic retention.
* Optional Beacon/Hub remote orchestration.
* WordPress.org-native update authority.
