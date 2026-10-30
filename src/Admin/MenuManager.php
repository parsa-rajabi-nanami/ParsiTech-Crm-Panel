<?php
declare(strict_types=1);
namespace ParsiShop\CRM\Admin;
use ParsiShop\CRM\Core\Capabilities;
if ( ! defined( 'ABSPATH' ) ) exit;

class MenuManager {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menus'], 9);
    }
    public static function add_menus() {
        add_menu_page(
            'ParsiReach',
            'ParsiReach',
            Capabilities::MANAGE_CAP,
            'parsishop-crm',
            [DashboardPage::class, 'render_page'],
            'dashicons-megaphone',
            56
        );
        add_submenu_page('parsishop-crm', 'داشبورد', 'داشبورد', Capabilities::MANAGE_CAP, 'parsishop-crm', [DashboardPage::class, 'render_page']);
    }
}
