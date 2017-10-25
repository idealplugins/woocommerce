<?php
class TargetPayInstall
{
    static $tp_methods = array('ide', 'mrc', 'deb', 'wal', 'cc', 'afp', 'bw', 'pyp');

    /**
     * install db when active plugin
     * - create new db
     * - migrate data from old db
     */
    public static function install_db()
    {
        global $wpdb;
        $targetpayTbl = $wpdb->prefix . TARGETPAY_TABLE_NAME;
        if(!$wpdb->get_var("SHOW TABLES LIKE '$targetpayTbl'") == $targetpayTbl) {
            self::create_tagetpay_db();
            self::migrate_data();
        }
        self::update_tagetpay_db();
    }

    /**
     * Create targetpay table
     */
    public static function create_tagetpay_db()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . TARGETPAY_TABLE_NAME . " (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `cart_id` int(11) NOT NULL DEFAULT '0',
        `order_id` varchar(11) NOT NULL DEFAULT '0',
        `rtlo` int(11) NOT NULL,
        `paymethod` varchar(8) NOT NULL DEFAULT 'IDE',
        `transaction_id` varchar(100) NOT NULL,
        `more` varchar(255) NULL,
        `testmode` varchar(3) NOT NULL DEFAULT 'no',
        `message` varchar(255) NULL,
        UNIQUE KEY id (id),
        KEY `cart_id` (`cart_id`),
        KEY `transaction_id` (`transaction_id`)
        ) " . $charset_collate . ";";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Update targetpay table => add more field
     */
    public static function update_tagetpay_db()
    {
        global $wpdb;
        $sql = "ALTER TABLE " . $wpdb->prefix . TARGETPAY_TABLE_NAME . " ADD `more` VARCHAR(255) NULL AFTER `transaction_id`;";
        $wpdb->query($sql);
    }

    /**
     * migrate data from old table to new one
     */
    public static function migrate_data()
    {
        global $wpdb;
        //setting
        $oldSetting = get_option('woocommerce_targetpay_settings');
        $oldRtlo = !empty($oldSetting['rtlo']) ? $oldSetting['rtlo'] : '';
        $oldTestmode = !empty($oldSetting['testmode']) ? $oldSetting['testmode'] : 'no';
        if(!empty($oldSetting)) {
            foreach (self::$tp_methods as $code) {
                $option_name = 'woocommerce_targetpay_' . strtolower($code) . '_settings';
                $old = get_option($option_name);
                $enabled = !empty($old['enabled']) ? $old['enabled'] : 'no';
                update_option($option_name, ['rtlo' => $oldRtlo, 'testmode' => $oldTestmode, 'orderStatus' => 'completed', 'enabled' => $enabled]);
            }
            delete_option( 'woocommerce_targetpay_settings' );
        }

        //data
        $oldTable = $wpdb->prefix . TARGETPAY_OLD_TABLE_NAME;
        if($wpdb->get_var("SHOW TABLES LIKE '$oldTable'") == $oldTable) {
            $sql = "INSERT INTO " . $wpdb->prefix . TARGETPAY_TABLE_NAME . " (`cart_id`, `order_id`, `rtlo`, `paymethod`, `transaction_id`)
                    SELECT `cart_id`, `order_id`, `rtlo`, `paymethod`, `transaction_id` FROM " . $oldTable;
            $wpdb->query($sql);
        }
    }
}
