<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_Activator {
    public static function activate() {
        self::install_schema();
        self::install_roles();
        self::install_defaults();
        self::schedule_events();

        CEIA_Repository::seed_global_sources();
        CEIA_Repository::sync_tramites();

        flush_rewrite_rules( false );
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'ceia_daily_queue' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'ceia_daily_queue' );
        }
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $tables  = CEIA_Repository::tables();

        $queries = array();

        $queries[] = "CREATE TABLE {$tables['items']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(20) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            title varchar(255) NOT NULL,
            url varchar(2048) NOT NULL,
            category varchar(255) NOT NULL DEFAULT '',
            risk varchar(20) NOT NULL DEFAULT 'medium',
            active tinyint(1) NOT NULL DEFAULT 1,
            review_interval_days smallint(5) unsigned NOT NULL DEFAULT 90,
            next_review_gmt datetime NULL,
            last_review_gmt datetime NULL,
            last_status varchar(40) NOT NULL DEFAULT 'never_reviewed',
            content_hash char(64) NOT NULL DEFAULT '',
            created_gmt datetime NOT NULL,
            updated_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY object_ref (object_type,object_id),
            KEY post_id (post_id),
            KEY due (active,next_review_gmt),
            KEY last_status (last_status)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$tables['sources']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            label varchar(255) NOT NULL,
            url varchar(2048) NOT NULL,
            source_type varchar(40) NOT NULL DEFAULT 'institutional',
            authority tinyint(3) unsigned NOT NULL DEFAULT 60,
            active tinyint(1) NOT NULL DEFAULT 1,
            last_checked_gmt datetime NULL,
            last_http_status smallint(5) unsigned NOT NULL DEFAULT 0,
            content_hash char(64) NOT NULL DEFAULT '',
            created_gmt datetime NOT NULL,
            updated_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY item_active (item_id,active),
            KEY authority (authority)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$tables['jobs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            item_id bigint(20) unsigned NOT NULL,
            trigger_type varchar(30) NOT NULL DEFAULT 'manual',
            state varchar(30) NOT NULL DEFAULT 'queued',
            requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
            requested_gmt datetime NOT NULL,
            claimed_gmt datetime NULL,
            finished_gmt datetime NULL,
            worker_id varchar(191) NOT NULL DEFAULT '',
            attempt tinyint(3) unsigned NOT NULL DEFAULT 0,
            payload longtext NULL,
            summary text NULL,
            error_code varchar(80) NOT NULL DEFAULT '',
            error_message text NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            KEY queue (state,requested_gmt),
            KEY item_id (item_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$tables['evidence']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            item_id bigint(20) unsigned NOT NULL,
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            local_id varchar(20) NOT NULL DEFAULT '',
            url varchar(2048) NOT NULL,
            title varchar(500) NOT NULL DEFAULT '',
            source_type varchar(40) NOT NULL DEFAULT 'institutional',
            authority tinyint(3) unsigned NOT NULL DEFAULT 0,
            retrieved_gmt datetime NOT NULL,
            published_date date NULL,
            http_status smallint(5) unsigned NOT NULL DEFAULT 0,
            content_hash char(64) NOT NULL DEFAULT '',
            excerpt longtext NULL,
            facts_json longtext NULL,
            created_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY item_id (item_id),
            KEY source_id (source_id),
            KEY local_ref (job_id,local_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$tables['proposals']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            item_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'review_required',
            risk varchar(20) NOT NULL DEFAULT 'medium',
            validation_status varchar(50) NOT NULL DEFAULT 'human_review',
            proposed_title varchar(255) NOT NULL DEFAULT '',
            summary longtext NULL,
            current_hash char(64) NOT NULL DEFAULT '',
            current_title varchar(255) NOT NULL DEFAULT '',
            current_content longtext NULL,
            current_fields_json longtext NULL,
            proposed_content longtext NULL,
            proposed_fields_json longtext NULL,
            changes_json longtext NULL,
            facts_json longtext NULL,
            conflicts_json longtext NULL,
            citations_json longtext NULL,
            created_gmt datetime NOT NULL,
            updated_gmt datetime NOT NULL,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_gmt datetime NULL,
            review_note text NULL,
            published_by bigint(20) unsigned NOT NULL DEFAULT 0,
            published_gmt datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_id (job_id),
            KEY status (status,created_gmt),
            KEY item_id (item_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$tables['logs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action varchar(80) NOT NULL,
            object_type varchar(30) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor varchar(191) NOT NULL DEFAULT '',
            detail longtext NULL,
            created_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY object_ref (object_type,object_id),
            KEY created_gmt (created_gmt)
        ) {$charset};";

        foreach ( $queries as $query ) {
            dbDelta( $query );
        }

        $settings = get_option( 'ceia_settings', array() );
        if ( is_array( $settings ) ) {
            unset( $settings['openai_api_key'], $settings['openai_model'] );
            $settings['analysis_provider'] = 'gemini';
            $settings['automatic_queue']   = 0;
            update_option( 'ceia_settings', $settings, false );
        }

        update_option( 'ceia_db_version', CEIA_DB_VERSION, false );
    }

    public static function install_roles() {
        $administrator = get_role( 'administrator' );
        $capabilities  = array(
            'ceia_manage_settings',
            'ceia_run_audits',
            'ceia_review_proposals',
            'ceia_publish_proposals',
            'ceia_submit_research',
        );

        if ( $administrator ) {
            foreach ( $capabilities as $capability ) {
                $administrator->add_cap( $capability );
            }
        }

        add_role(
            'ceia_research_worker',
            'Trabajador CE-IA',
            array(
                'read'                 => true,
                'ceia_submit_research' => true,
            )
        );
    }

    public static function install_defaults() {
        if ( false === get_option( 'ceia_settings', false ) ) {
            add_option(
                'ceia_settings',
                array(
                    'notification_email'   => 'web.cest@uniovi.es',
                    'automatic_queue'      => 0,
                    'daily_queue_limit'    => 5,
                    'max_jobs_per_run'     => 5,
                    'max_sources_per_job'  => 12,
                    'max_searches_per_job' => 2,
                    'max_source_bytes'     => 6000000,
                    'analysis_provider'    => 'gemini',
                    'gemini_model'         => 'gemini-3.1-flash-lite',
                    'tavily_enabled'       => 0,
                    'github_owner'         => 'proyectodocenciauo-cmyk',
                    'github_repository'    => 'ce-uniovi-auditor-ia',
                    'github_workflow'      => 'audit.yml',
                    'github_branch'        => 'main',
                ),
                '',
                false
            );
        }
    }

    public static function schedule_events() {
        if ( ! wp_next_scheduled( 'ceia_daily_queue' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ceia_daily_queue' );
        }
    }
}
