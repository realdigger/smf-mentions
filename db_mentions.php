<?php
/**
 * Database installation script for @mentions mod
 *
 * @author Shitiz Garg <mail@dragooon.net>
 * @copyright 2014 Shitiz Garg
 * @license Simplified BSD (2-Clause) License
 */

if (!defined('SMF'))
    require_once('SSI.php');

global $smcFunc;

db_extend('Packages');
db_extend('Extra');

$smcFunc['db_create_table']('{db_prefix}log_mentions', array(
    array('name' => 'id_post', 'type' => 'int'),
    array('name' => 'id_member', 'type' => 'int'),
    array('name' => 'id_mentioned', 'type' => 'int'),
    array('name' => 'time', 'type' => 'int', 'null' => false, 'default' => 0),
    array('name' => 'unseen', 'type' => 'int', 'null' => false, 'default' => 1),
), array(
    array('type' => 'primary', 'columns' => array('id_post', 'id_member', 'id_mentioned')),
));
$smcFunc['db_add_column']('{db_prefix}members', array(
    'name' => 'email_mentions', 'type' => 'tinyint', 'null' => false, 'default' => 0,
));

$hooks = array(
    'integrate_pre_include' => '$sourcedir/Mentions.php',
    'integrate_profile_areas' => 'mentions_profile_areas',
    'integrate_load_permissions' => 'mentions_permissions',
    'integrate_bbc_codes' => 'mentions_bbc',
    'integrate_menu_buttons' => 'mentions_menu',
);

foreach ($hooks as $hook => $function)
    add_integration_function($hook, $function);