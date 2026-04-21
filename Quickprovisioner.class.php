<?php
namespace FreePBX\modules;

// Configuration constants
define('QP_FREEPBX_BASE_PATH', '/var/www/html');
define('QP_GIT_COMMAND', '/usr/bin/git');
define('QP_FWCONSOLE_RELOAD', '/usr/sbin/fwconsole reload');
define('QP_FWCONSOLE_RESTART', '/usr/sbin/fwconsole restart');
define('QP_FWCONSOLE_CHOWN', '/usr/sbin/fwconsole chown');

class Quickprovisioner extends \FreePBX_Helpers implements \BMO {
    public function install() {}
    public function uninstall() {}
    public function backup() {}
    public function restore($backup) {}
    public function doConfigPageInit($page) {}

    public function ajaxRequest($req, &$setting) {
        return true;
    }

    public function ajaxHandler() {
        $command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';
        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : $command;

        switch($action) {
            case 'restart_pbx':
                $type = isset($_POST['type']) ? $_POST['type'] : 'reload';
                if (!in_array($type, array('reload', 'restart'), true)) {
                    return array('status' => false, 'message' => 'Invalid restart type');
                }
                $allowed_commands = array(
                    'reload' => QP_FWCONSOLE_RELOAD,
                    'restart' => QP_FWCONSOLE_RESTART
                );
                $cmd = $allowed_commands[$type];
                $output = array();
                $return_var = 0;
                exec($cmd . ' 2>&1', $output, $return_var);
                if ($return_var === 0) {
                    return array('status' => true, 'message' => 'PBX ' . $type . ' completed successfully');
                } else {
                    return array('status' => false, 'message' => 'PBX ' . $type . ' failed', 'output' => implode("\n", $output));
                }
                break;

            default:
                global $db;
                $db = \FreePBX::Database();
                $_REQUEST['action'] = $action;
                ob_start();
                include(__DIR__ . '/ajax.quickprovisioner.php');
                $output = ob_get_clean();
                $result = json_decode($output, true);
                return $result ? $result : array('status' => false, 'message' => 'Unknown action', 'raw' => $output);
        }
    }
}
