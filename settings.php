<?php
    function woocci_settings_init()
    {
        // register a new settings for "general" page
        register_setting('general', 'woocci_new_file_uri');
        register_setting('general', 'woocci_update_file_uri');
        register_setting('general', 'woocci_markup', array('type' => 'number', 'default' => 0));
        register_setting('general', 'woocci_markup_precision', array('type' => 'number', 'default' => 1));
        register_setting('general', 'woocci_cron', array(
            'type' => 'string', 
            'default' => '+0 hour', 
            'sanitize_callback' => 'woocci_update_cron'
        ));
        register_setting('general', 'woocci_cron_interval', array(
            'type' => 'string', 
            'default' => 'daily', 
            'sanitize_callback' => 'woocci_update_cron_interval'
        ));
        register_setting('general', 'woocci_init_webhook');
        register_setting('general', 'woocci_init_action_key');
        register_setting('general', 'woocci_new_mapping');
        register_setting('general', 'woocci_update_mapping');
    
        // register a new section in the "reading" page
        add_settings_section(
            'woocci_settings_section',
            'Woo CSV Cron Importer',
            'woocci_settings_section_cb',
            'general'
        );
    
        // register setting fileds
        add_settings_field(
            'woocci_new_file_uri',
            'Путь до файла импорта с новыми товарами',
            'woocci_new_file_uri_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_update_file_uri',
            'Путь до файла импорта с обновляемыми товарами',
            'woocci_update_file_uri_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_markup',
            'Процент наценки при импорте',
            'woocci_markup_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_markup_precision',
            'Точность округления (знаков после запятой)',
            'woocci_markup_precision_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_cron',
            'Время запуска',
            'woocci_cron_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_cron_interval',
            'Периодичность запуска',
            'woocci_cron_interval_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_init_webhook',
            'Адрес WebHook\'а сторонней системы',
            'woocci_init_webhook_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_init_action_key',
            'Ключ, необходимый для запуска процесса импорта из сторонней системы',
            'woocci_init_action_key_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_new_mapping',
            'Сопоставление полей (для новых товаров)',
            'woocci_new_mapping_field_cb',
            'general',
            'woocci_settings_section'
        );

        add_settings_field(
            'woocci_update_mapping',
            'Сопоставление полей (для обновляемых товаров)',
            'woocci_update_mapping_field_cb',
            'general',
            'woocci_settings_section'
        );
    }
    
    /**
     * register woocci_settings_init to the admin_init action hook
     */
    add_action('admin_init', 'woocci_settings_init');
    
    /**
     * callback functions
     */
    
    // section content cb
    function woocci_settings_section_cb()
    {
       ?><span id="woocci_settings"></span><?php
    }

    function woocci_next_schedule( $input ) {
        $midnight_offset = $input;
        $timestamp = strtotime( 'today ' . $midnight_offset );
        if ( $timestamp < current_time('timestamp', false) ) {
            $timestamp = strtotime( 'tomorrow ' . $midnight_offset );
        }
        // sub timezone offset
        return $timestamp - wc_timezone_offset();
    }

    function woocci_reschedule( $timestamp, $interval ) {
        // delete old event
        $next_timestamp = wp_next_scheduled( 'bl_woocci_cron_hook' );
        wp_unschedule_event( $next_timestamp, 'bl_woocci_cron_hook' );

        wp_schedule_event( $timestamp, $interval, 'bl_woocci_cron_hook' );
    }

    function woocci_update_cron( $input ) {
        woocci_reschedule( woocci_next_schedule( $input ), get_option( 'woocci_cron_interval' ) );
        return $input;
    }

    function woocci_update_cron_interval( $interval ) {
        woocci_reschedule( woocci_next_schedule( get_option( 'woocci_cron' ) ), $interval );
        return $interval;
    }
    
    // field new file uri
    function woocci_new_file_uri_field_cb()
    {
        $uri = get_option('woocci_new_file_uri');
        ?>
        <input name="woocci_new_file_uri" value="<?php echo isset( $uri ) ? esc_attr( $uri ) : ''; ?>">
        <?php echo woocci_check_file_exists( $uri) ? 'Файл найден' : 'Файл не найден' ?>
        <?php
    }

    // field update file uri
    function woocci_update_file_uri_field_cb()
    {
        $uri = get_option('woocci_update_file_uri');
        ?>
        <input name="woocci_update_file_uri" value="<?php echo isset( $uri ) ? esc_attr( $uri ) : ''; ?>">
        <?php echo woocci_check_file_exists( $uri) ? 'Файл найден' : 'Файл не найден' ?>
        <?php
    }

    // field markup
    function woocci_markup_field_cb()
    {
        $markup = get_option('woocci_markup');
        ?>
        <input type="number" name="woocci_markup" value="<?php echo isset( $markup ) ? esc_attr( $markup ) : '0'; ?>"> %
        <?php
    }

    // field markup_precision
    function woocci_markup_precision_field_cb()
    {
        $precision = get_option('woocci_markup_precision');
        ?>
        <input type="number" name="woocci_markup_precision" value="<?php echo isset( $precision ) ? esc_attr( $precision ) : '0'; ?>">
        <?php
    }

    // field cron
    function woocci_cron_field_cb()
    {
        $next_timestamp = wp_next_scheduled( 'bl_woocci_cron_hook' ) + wc_timezone_offset();
        $cron = get_option( 'woocci_cron' );
        ?>
        <select name="woocci_cron">
        <?php
        for($h = 0; $h < 24; $h++) {
            $value = "+" . $h . " hour";
            $name = sprintf("%2d:00", $h);
            ?>
            <option value="<?php echo $value; ?>" <?php echo $cron == $value ? ' selected' : ''; ?>><?php echo $name; ?></option>
            <?php
        }
        ?>
        </select> <?php if($next_timestamp): ?>(запланировано на <?php echo date_i18n( 'r', $next_timestamp, false ); ?>)<?php else: ?>(не запланировано)<?php endif; ?>
        <?php
    }

    // field cron_interval
    function woocci_cron_interval_field_cb()
    {
        $intervals = array(
            'hourly' => 'Каждый час',
            'twicedaily' => 'Дважды в день',
            'daily' => 'Ежедневно',
            'weekly' => 'Еженедельно',
        );
        $cron_interval = get_option('woocci_cron_interval');
        ?>
        <select name="woocci_cron_interval">
        <?php
        foreach($intervals as $value => $name) {
            ?>
            <option value="<?php echo $value; ?>" <?php echo $cron_interval == $value ? ' selected' : ''; ?>><?php echo $name; ?></option>
            <?php
        }
        ?>
        </select>
        <?php
    }

    // field init_webhook
    function woocci_init_webhook_field_cb()
    {
        $webhook = get_option('woocci_init_webhook');
        ?>
        <input name="woocci_init_webhook" value="<?php echo isset( $webhook ) ? esc_attr( $webhook ) : ''; ?>"> <?php echo isset( $webhook ) ? '<b style="color: red">запуск обновления только после обратного вызова</b>' : ''; ?>
        <?php
    }

    // field init_action_key
    function woocci_init_action_key_field_cb()
    {
        $key = get_option('woocci_init_action_key');
        if($key == "") {
            $bytes = random_bytes(16);
            $key = bin2hex($bytes);
            update_option('woocci_init_action_key', $key);
        }
        ?>
        <input name="woocci_init_action_key" value="<?php echo isset( $key ) ? esc_attr( $key ) : ''; ?>">  <br /> адрес обратного вызова: <?php echo admin_url('admin-ajax.php') . '?action=init_action&key=' . $key; ?>
        <?php
    }

    // field mapping (new)
    function woocci_new_mapping_field_cb()
    {
        $mapping = get_option('woocci_new_mapping');
        ?>
        <input name="woocci_new_mapping" value="<?php echo isset( $mapping ) ? esc_attr( $mapping ) : ''; ?>"> разделенные запятой
        <?php
    }

    // field mapping (update)
    function woocci_update_mapping_field_cb()
    {
        $mapping = get_option('woocci_update_mapping');
        ?>
        <input name="woocci_update_mapping" value="<?php echo isset( $mapping ) ? esc_attr( $mapping ) : ''; ?>"> разделенные запятой
        <?php
    }
?>