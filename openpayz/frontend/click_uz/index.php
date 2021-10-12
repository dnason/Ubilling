<?php

/**
 * Click.UZ API frontend for OpenPayz
 *
 * https://docs.click.uz/click-api/
 *
 * with implementation of getinfo() request
 *
 * For a proper functioning of this frontend you need to deal with ClickUZ
 * for they to use a "payment_method" GET parameter with your frontend endpoint URL
 *
 * Possible values of "payment_method" GET parameter are:
 * getinfo
 * prepare
 * complete
 */

// подключаем API OpenPayz
include ("../../libs/api.openpayz.php");

class ClickUZ {
    /**
     * Predefined stuff
     */
    const PATH_CONFIG       = 'config/clickuz.ini';
    const PATH_AGENTCODES   = 'config/agentcodes_mapping.ini';
    const PATH_TRANSACTS    = 'tmp/';

    /**
     * Paysys specific predefines
     */
    const HASH_PREFIX = 'CLICK_UZ_';
    const PAYSYS = 'CLICK_UZ';

    /**
     * Actions codes here
     */
    const ACT_INFO = 0;
    const ACT_PREPARE = 1;
    const ACT_CONFIRM = 2;

    /**
     * Agent codes using flag
     *
     * @var bool
     */
    protected $agentcodesON = false;

    /**
     * Non strict agent codes using flag
     *
     * @var bool
     */
    protected $agentcodesNonStrict = false;

    /**
     * Contains values from agentcodes_mapping.ini
     *
     * @var array
     */
    protected $agentcodesMapping = array();

    /**
     * Merchant secret key from ClickUZ
     *
     * @var string
     */
    protected $secretKey = '';

    /**
     * Placeholder for UB API URL
     *
     * @var string
     */
    protected $ubapiURL = '';

    /**
     * Placeholder for UB API key
     *
     * @var string
     */
    protected $ubapiKey = '';

    /**
     * Placeholder for CITY_DISPLAY_IN_ADDRESS config option
     *
     * @var bool
     */
    protected $addressCityDisplay = false;

    /**
     * Instance configuration as key=>value
     *
     * @var array
     */
    protected $config = array();

    /**
     * Placeholder for a "payment_method" GET parameter
     *
     * @var string
     */
    protected $paymentMethod = '';

    /**
     * Placeholder for available payment methods
     *
     * @var array
     */
    protected $paymentMethodsAvailable = array('getinfo', 'prepare', 'complete');

    /**
     * Placeholder for ClickUZ service ID value
     *
     * @var string
     */
    protected $serviceID = '';

    /**
     * Placeholder for ClickUZ customer ID value
     *
     * @var string
     */
    protected $customerID = '';

    /**
     * Placeholder for ClickUZ transaction ID value
     *
     * @var string
     */
    protected $clickTransactID = '';

    /**
     * Contains received by listener preprocessed request data
     *
     * @var array
     */
    protected $receivedJSON = array();

    /**
     * Contains all existent op_customers as virtualid => realid(login) mapping
     *
     * @var array
     */
    protected $opCustomersAll = array();

    /**
     * Placeholder for OP customer login
     *
     * @var string
     */
    protected $userLogin = '';

    /**
     * Placeholder for error codes and their descr
     *
     * @var string
     */
    protected $errorCodes = array('0' => 'Success',
                                  '-1' => 'SIGN CHECK FAILED!',
                                  '-2' => 'Incorrect parameter amount',
                                  '-3' => 'Action not found',
                                  '-4' => 'Already paid',
                                  '-5' => 'User does not exist by params',
                                  '-6' => 'Transaction does not exist',
                                  '-7' => 'Failed to update user',
                                  '-8' => 'Error in request from click',
                                  '-9' => 'Transaction cancelled'
                                );

    /**
     * Preloads all required configuration, sets needed object properties
     *
     * @return void
     */
    public function __construct() {
        $this->loadConfig();
        $this->setOptions();
        $this->loadACMapping();
    }

    /**
     * Loads frontend configuration in protected prop
     *
     * @return void
     */
    protected function loadConfig() {
        if (file_exists(self::PATH_CONFIG)) {
            $this->config = parse_ini_file(self::PATH_CONFIG);
        } else {
            die('Fatal error: config file ' . self::PATH_CONFIG . ' not found!');
        }
    }

    /**
     * Loads frontend agentcodes_mapping.ini in protected prop
     *
     * @return void
     */
    protected function loadACMapping() {
        if ($this->agentcodesON) {
            if (file_exists(self::PATH_AGENTCODES)) {
                $this->agentcodesMapping = parse_ini_file(self::PATH_AGENTCODES);
            } else {
                die('Fatal error: agentcodes_mapping.ini file ' . self::PATH_AGENTCODES . ' not found!');
            }
        }
    }

    /**
     * Sets object properties based on frontend config
     *
     * @return void
     */
    protected function setOptions() {
        if (!empty($this->config)) {
            $this->agentcodesON         = $this->config['USE_AGENTCODES'];
            $this->agentcodesNonStrict  = $this->config['NON_STRICT_AGENTCODES'];
            $this->secretKey            = $this->config['SECRET_KEY'];
            $this->ubapiURL             = $this->config['UBAPI_URL'];
            $this->ubapiKey             = $this->config['UBAPI_KEY'];
            $this->addressCityDisplay   = $this->config['CITY_DISPLAY_IN_ADDRESS'];
        } else {
            die('Fatal: config is empty!');
        }
    }

    protected function checkServiceAgentAssign($userLogin) {
        $result     = false;
        $agentData  = json_decode($this->getUBAgentData($userLogin), true);

        if (!empty($agentData['id'])) {
            // get Service ID to Ubilling agent code mapping, if exists
            $mappedAgentBySrvID = (empty($this->agentcodesMapping[$this->serviceID]) ? 'n0ne' : $this->agentcodesMapping[$this->serviceID]);
            // get current subscriber agent ID
            $agentID = $agentData['id'];
            // compare the IDs
            $result  = ($agentID == $mappedAgentBySrvID);
        }

        // if $result is false and $this->agentcodesNonStrict is ON - make $result true
        $result = ((!$result and $this->agentcodesNonStrict) ? true : $result);
        return ($result);
    }

    /**
     * Gets user associated agent data JSON
     *
     * @param string $userLogin
     *
     * @return string
     */
    protected function getUBAgentData($userLogin) {
        $action = $this->ubapiURL . '?module=remoteapi&key=' . $this->ubapiKey . '&action=getagentdata&param=' . $userLogin;
        @$result = file_get_contents($action);
        return ($result);
    }

    /**
     * Returns user stargazer data by login
     *
     * @param string $userLogin existing stargazer login
     *
     * @return array
     */
    protected function getUserStargazerData($userLogin) {
        $userLogin = mysql_real_escape_string($userLogin);
        $query     = "SELECT * from `users` WHERE `login`='" . $userLogin . "';";
        $result    = simple_query($query);
        return ($result);
    }

    /**
     * Returns all user RealNames
     *
     * @return array
     */
    protected function getUserRealnames($userLogin) {
        $result = array();
        $whereStr = (empty($userLogin) ? '' : " WHERE `login` = '" . $userLogin . "'");

        $query = "SELECT * from `realname`" . $whereStr;
        $realnames = simple_queryall($query);

        if (!empty($realnames)) {
            foreach ($realnames as $io => $each) {
                $result[$each['login']] = $each['realname'];
            }
        }

        $result = (empty($userLogin) ? $result : $result[$userLogin]);
        return($result);
    }


    /**
     * Returns array of available or filtered by user login address as login => address
     *
     * @param string $userLogin
     *
     * @return array|string
     */
    protected function getUserAddresses($userLogin) {
        $result = array();
        $whereStr = (empty($userLogin) ? '' : " WHERE `address`.`login` = '" . $userLogin . "'");

        $query = "
            SELECT `address`.`login`,`city`.`cityname`,`street`.`streetname`,`build`.`buildnum`,`apt`.`apt` 
                FROM `address`
                    INNER JOIN `apt` ON `address`.`aptid`= `apt`.`id`
                    INNER JOIN `build` ON `apt`.`buildid`=`build`.`id`
                    INNER JOIN `street` ON `build`.`streetid`=`street`.`id`
                    INNER JOIN `city` ON `street`.`cityid`=`city`.`id`"
                . $whereStr;

        $addresses = simple_queryall($query);

        if (!empty($addresses)) {
            foreach ($addresses as $eachAddress) {
                // zero apt handle
                $apartment_filtered = ($eachAddress['apt'] == 0) ? '' : '/' . $eachAddress['apt'];

                if ($this->addressCityDisplay) {
                    $result[$eachAddress['login']] = $eachAddress['cityname'] . ' ' . $eachAddress['streetname'] . ' ' . $eachAddress['buildnum'] . $apartment_filtered;
                } else {
                    $result[$eachAddress['login']] = $eachAddress['streetname'] . ' ' . $eachAddress['buildnum'] . $apartment_filtered;
                }
            }
        }

        $result = (empty($userLogin) ? $result : $result[$userLogin]);
        return($result);
    }

    protected function createSign($actCode) {

    }

    protected function validateSign() {

    }

    /**
     * Saves transaction id to validate some possible duplicates
     *
     * @return void
     */
    protected function saveTransaction() {
//todo: change to fit specs
        if (!empty($this->receivedData)) {
            if (isset($this->receivedData['PAY_ID'])) {
                file_put_contents(self::PATH_TRANSACTS . $this->receivedData['PAY_ID'], serialize($this->receivedData));
            }
        }
    }

    protected function replyGetInfo() {
        $userData = $this->getUserStargazerData($this->userLogin);

        if (!empty($userData)) {
            $userBalance = $userData['Cash'];
        }
    }

    protected function replyPrepare() {

    }

    protected function replyComplete() {

    }

    /**
     * Returns JSON-encoded error reply
     *
     * @param $errorCode
     *
     * @return false|string
     */
    protected function replyError($errorCode) {
        $reply = array();

        if ($this->paymentMethod == 'getinfo') {
            $reply = array('error'              => $errorCode,
                           'error_note'         => $this->errorCodes[$errorCode]
                          );
        } else {
            $merchTransactIDName = ($this->paymentMethod == 'prepare' ? 'merchant_prepare_id' : 'merchant_confirm_id');

            $reply = array('click_trans_id'     => $this->clickTransactID,
                           'merchant_trans_id'  => $this->customerID,
                           $merchTransactIDName => 'NULL',
                           'error'              => $errorCode,
                           'error_note'         => $this->errorCodes[$errorCode]
                          );
        }

        $reply = json_encode($reply);
        return ($reply);
    }

    protected function processRequest() {
        $this->opCustomersAll   = op_CustomersGetAll();
        $this->clickTransactID  = $this->receivedJSON['click_trans_id'];
        $this->serviceID        = $this->receivedJSON['service_id'];
        $this->customerID       = ($this->paymentMethod == 'getinfo'
                                    ? $this->receivedJSON['params']['contract']
                                    : $this->receivedJSON['merchant_trans_id']);

        if (!empty($this->opCustomersAll[$this->customerID])) {
            $this->userLogin = $this->opCustomersAll[$this->customerID];

            if ($this->agentcodesON and !$this->checkServiceAgentAssign($this->userLogin)) {
                die($this->replyError('-5'));
            }

            switch ($this->paymentMethod) {
                case 'getinfo':
                    $this->replyGetInfo();
                    break;

                case 'prepare':
                    $this->replyPrepare();
                    break;

                case 'complete':
                    $this->replyComplete();
                    break;

                default:
                    die($this->replyError('-3'));
            }

        }
    }

    /**
     * Listen to your heart when he's calling for you
     * Listen to your heart, there's nothing else you can do
     *
     * @return void
     */
    public function listen() {
        $this->paymentMethod = $_GET['payment_method'];

        if (in_array($this->paymentMethod, $this->paymentMethodsAvailable)) {
            $this->receivedJSON = json_decode(file_get_contents('php://input'), true);

            if (!empty($this->receivedJSON)) {
                $this->processRequest();
            }
        }
    }
}