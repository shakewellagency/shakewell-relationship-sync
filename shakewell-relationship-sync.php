<?php
/**
 * Plugin Name:       Shakewell Two-way Relationships for ACF
 * Plugin URI:        https://github.com/shakewellagency/shakewell-relationship-sync
 * Description:       Keeps ACF relationship and post object fields linked on both posts of a pair. Needs Advanced Custom Fields.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-relationship-sync
 *
 * @package Shakewell\RelationshipSync
 */

namespace Shakewell\RelationshipSync;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.1';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Plugin.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
