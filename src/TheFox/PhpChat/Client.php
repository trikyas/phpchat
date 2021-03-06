<?php

namespace TheFox\PhpChat;

use Exception;
use RuntimeException;
use Rhumsaa\Uuid\Uuid;
use Rhumsaa\Uuid\Exception\UnsatisfiedDependencyException;
use Zend\Uri\Uri;
use Zend\Uri\UriFactory;
use Colors\Color;
use TheFox\Dht\Simple\Node;
use TheFox\Pow\Hashcash;

class Client
{
    const MSG_SEPARATOR = "\n";

    const MSG_SEPARATOR_LEN = 1;

    const NODE_FIND_NUM = 8;

    const NODE_FIND_MAX_NODE_IDS = 1024;

    const HASHCASH_BITS_MIN = 12;

    const HASHCASH_BITS_MAX = 15;

    const HASHCASH_EXPIRATION = 172800; // 2 days

    const SSL_PASSWORD_TTL = 300;

    const SSL_PASSWORD_MSG_MAX = 100;

    const ACTIONS_INTERVAL = 30;

    /**
     * @var bool
     */
    public $debug = false;

    /**
     * @var int
     */
    protected $id = 0;

    private $status = [];

    private $server = null;

    protected $node = null;

    protected $uri = null;

    private $ssl = null;

    private $sslTestToken = '';

    private $sslPasswordToken = '';

    private $sslPasswordLocalCurrent = '';

    private $sslPasswordLocalNew = '';

    private $sslPasswordPeerCurrent = '';

    private $sslPasswordPeerNew = '';

    private $sslPasswordTime = 0;

    private $sslMsgCount = 0;

    private $requestsId = 0;

    private $requests = [];

    private $actionsId = 0;

    private $actions = [];

    #private $actionsTime = 0;
    private $bridgeActionsId = 0;

    private $bridgeActions = [];

    protected $pingTime = 0;

    protected $pongTime = 0;

    private $trafficIn = 0;

    private $trafficOut = 0;

    private $bridgeClient = null;

    private $recvBufferTmp = '';

    public function __construct()
    {
        $this->uri = new Uri();

        $this->status['hasId'] = false;
        $this->status['hasTalkRequest'] = false;
        $this->status['hasTalk'] = false;
        $this->status['hasTalkClose'] = false;
        $this->status['hasShutdown'] = false;

        $this->status['isChannelLocal'] = false;
        $this->status['isChannelPeer'] = false;
        $this->status['isOutbound'] = false;
        $this->status['isInbound'] = false;
        $this->status['bridgeServerUri'] = null;
        $this->status['bridgeTargetUri'] = null;

        $this->resetStatusSsl();
    }

    public function __sleep()
    {
        return ['id', 'uri', 'node'];
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getStatus($name)
    {
        if (array_key_exists($name, $this->status)) {
            return $this->status[$name];
        }
        return null;
    }

    public function setStatus($name, $value)
    {
        $this->status[$name] = $value;
    }

    private function resetStatusSsl()
    {
        $this->status['hasSslInit'] = false;
        $this->status['hasSendSslInit'] = false;
        $this->status['hasSslInitOk'] = false;
        $this->status['hasSslTest'] = false;
        $this->status['hasSslVerify'] = false;
        $this->status['hasSslPasswortPut'] = false;
        $this->status['hasReSslPasswortPutInit'] = false;
        $this->status['hasReSslPasswortPut'] = false;
        $this->status['hasSslPasswortTest'] = false;
        $this->status['hasReSslPasswortTest'] = false;
        $this->status['hasSslPasswortVerify'] = false;
        $this->status['hasSsl'] = false;

        $this->status['hasSendReSslPasswortPut'] = false;
        $this->status['hasReSslPasswortPut'] = false;
        $this->status['hasReSslPasswortTest'] = false;
    }

    public function setServer(Server $server)
    {
        $this->server = $server;
    }

    public function getServer()
    {
        return $this->server;
    }

    public function setNode(Node $node)
    {
        $this->node = $node;
    }

    public function getNode()
    {
        return $this->node;
    }

    public function setUri($uri)
    {
        if (is_string($uri)) {
            if ($uri) {
                $uri = UriFactory::factory($uri);
            } else {
                $uri = UriFactory::factory('tcp://');
            }
        }
        $this->uri = $uri;
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function setSsl($ssl)
    {
        $this->ssl = $ssl;
    }

    public function getSsl()
    {
        return $this->ssl;
    }

    public function setSslPrv($sslKeyPrvPath, $sslKeyPrvPass)
    {
        $this->logColor('debug', 'SSL setup', 'green');

        $rv = false;

        $content = file_get_contents($sslKeyPrvPath);
        $sslHandle = openssl_pkey_get_private($content, $sslKeyPrvPass);
        if ($sslHandle !== false) {
            $this->logColor('debug', 'SSL setup ok', 'green');
            $this->setSsl($sslHandle);
            $rv = true;
        } else {
            $this->logColor('debug', 'SSL failed', 'green');
            while ($openSslErrorStr = openssl_error_string()) {
                $this->log('error', 'SSL: ' . $openSslErrorStr);
            }
        }

        return $rv;
    }

    public function getLocalNode()
    {
        if ($this->getServer()) {
            return $this->getServer()->getLocalNode();
        }
        return null;
    }

    public function getSettings()
    {
        if ($this->getServer()) {
            return $this->getServer()->getSettings();
        }

        return null;
    }

    public function getLog()
    {
        if ($this->getServer()) {
            return $this->getServer()->getLog();
        }

        return null;
    }

    public function log($level, $msg)
    {
        if ($this->getLog()) {
            if (method_exists($this->getLog(), $level)) {
                $this->getLog()->$level($msg);
            }
        }
        /*else{
		}*/
    }

    public function logColor($level, $msg, $colorBg = 'green', $colorFg = 'black')
    {
        $color = new Color();
        #$this->log($level, $color($msg)->bg($colorBg));
        $this->log($level, $color($msg)->bg($colorBg)->fg($colorFg));
    }

    public function getTable()
    {
        if ($this->getServer()) {
            return $this->getServer()->getTable();
        }

        return null;
    }

    public function getMsgDb()
    {
        if ($this->getServer()) {
            return $this->getServer()->getMsgDb();
        }

        return null;
    }

    public function getHashcashDb()
    {
        if ($this->getServer()) {
            return $this->getServer()->getHashcashDb();
        }

        return null;
    }

    public function hashcashMint($bits = null)
    {
        $stamp = null;

        if ($bits === null) {
            $bits = static::HASHCASH_BITS_MIN;
        }
        if ($this->getLocalNode()) {
            $hashcash = new Hashcash($bits, $this->getLocalNode()->getIdHexStr());
            $hashcash->setDate(date(Hashcash::DATE_FORMAT12));

            try {
                $stamp = $hashcash->mint();
            } // @codeCoverageIgnoreStart
            catch (Exception $e) {
                $this->log('error', $e->getMessage());
            }
            // @codeCoverageIgnoreEnd
        }

        return $stamp;
    }

    public function hashcashVerify($hashcashStr, $resource, $bits = null)
    {
        #$this->log('debug', 'hashcash: '.$hashcashStr);

        if ($bits === null) {
            $bits = static::HASHCASH_BITS_MIN;
        }

        $hashcash = new Hashcash();
        $hashcash->setExpiration(static::HASHCASH_EXPIRATION);
        try {
            if ($hashcash->verify($hashcashStr)) {
                #$this->log('debug', 'bits: '.$hashcash->getBits());
                $added = false;
                if (
                    $hashcash->getVersion() >= 1
                    && $hashcash->getBits() >= $bits
                    && $hashcash->getResource() == $resource
                    && $added = $this->getHashcashDb()->addHashcash($hashcash)
                ) {
                    #$this->log('debug', 'hashcash: OK');
                    return true;
                } else {
                    $this->log('error', 'hashcash verification failed');
                    $this->log('error', 'hashcash version: ' . $hashcash->getVersion());
                    $this->log('error', 'hashcash bit: ' . $hashcash->getBits() . ' (min: ' . $bits . ')');
                    $this->log('error', 'hashcash resource: ' . $hashcash->getResource() . ' (' . $resource . ')');
                    $this->log('error', 'hashcash added: ' . ($added ? 'yes' : 'no'));
                }
            }
        } // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->log('warning', $e->getMessage());
        }
        // @codeCoverageIgnoreEnd

        $this->log('debug', 'hashcash: ' . $hashcashStr . ' failed');
        return false;
    }

    public function requestAdd($name, $rid, $data = [])
    {
        $this->requestsId++;

        $request = [
            'id' => $this->requestsId,
            'name' => $name,
            'rid' => $rid,
            'data' => $data,
        ];

        $this->requests[$this->requestsId] = $request;

        return $request;
    }

    public function requestGetByRid($rid)
    {
        foreach ($this->requests as $requestId => $request) {
            if ($request['rid'] == $rid) {
                return $request;
            }
        }
        return null;
    }

    public function requestRemove($request)
    {
        unset($this->requests[$request['id']]);
    }

    public function actionsExecute($criterion)
    {
        $actions = $this->actionsGetByCriterion($criterion);

        $this->log('debug', 'actions execute: ' . count($actions));
        foreach ($actions as $actionId => $action) {
            $this->log('debug', 'action execute: /' . $action->getName() . '/ /' . join(',', $action->getCriteria()) . '/');
            $this->actionRemove($action);
            $action->functionExec($this);
        }
        $this->log('debug', 'actions left: ' . count($this->actions));
    }

    public function actionsAdd($actions)
    {
        foreach ($actions as $action) {
            $this->actionAdd($action);
        }
    }

    public function actionAdd(ClientAction $action)
    {
        $this->actionsId++;

        $action->setId($this->actionsId);

        $this->actions[$this->actionsId] = $action;
    }

    public function actionsGetByCriterion($criterion)
    {
        $rv = [];
        foreach ($this->actions as $actionsId => $action) {
            if ($action->hasCriterion($criterion)) {
                $rv[] = $action;
            }
        }
        return $rv;
    }

    public function actionGetByCriterion($criterion)
    {
        foreach ($this->actions as $actionsId => $action) {
            if ($action->hasCriterion($criterion)) {
                return $action;
            }
        }

        return null;
    }

    public function actionRemove(ClientAction $action)
    {
        unset($this->actions[$action->getId()]);
    }

    public function bridgeActionsExecute($criterion)
    {
        $bridgeActions = $this->bridgeActionsGetByCriterion($criterion);

        $this->logColor('debug', 'bridgeActions execute: ' . count($bridgeActions), 'yellow');
        foreach ($bridgeActions as $bridgeActionId => $bridgeAction) {
            $logTmp = '/' . $bridgeAction->getName() . '/ /' . join(',', $bridgeAction->getCriteria()) . '/';
            $this->logColor('debug', 'bridgeAction execute: ' . $logTmp, 'yellow');
            $this->bridgeActionRemove($bridgeAction);
            $bridgeAction->functionExec($this);
        }
        $this->logColor('debug', 'bridgeActions left: ' . count($this->bridgeActions), 'yellow');
    }

    public function bridgeActionsAdd($bridgeActions)
    {
        foreach ($bridgeActions as $bridgeAction) {
            $this->bridgeActionAdd($bridgeAction);
        }
    }

    public function bridgeActionAdd(ClientAction $bridgeAction)
    {
        $this->bridgeActionsId++;

        $bridgeAction->setId($this->bridgeActionsId);

        $this->bridgeActions[$this->bridgeActionsId] = $bridgeAction;
    }

    public function bridgeActionsGetByCriterion($criterion)
    {
        $rv = [];
        foreach ($this->bridgeActions as $bridgeActionsId => $bridgeAction) {
            if ($bridgeAction->hasCriterion($criterion)) {
                $rv[] = $bridgeAction;
            }
        }
        return $rv;
    }

    public function bridgeActionGetByCriterion($criterion)
    {
        foreach ($this->bridgeActions as $bridgeActionsId => $bridgeAction) {
            if ($bridgeAction->hasCriterion($criterion)) {
                return $bridgeAction;
            }
        }

        return null;
    }

    public function bridgeActionRemove(ClientAction $action)
    {
        unset($this->bridgeActions[$action->getId()]);
    }

    public function bridgeActionRemoveByCriterion($criterion)
    {
        foreach ($this->bridgeActionsGetByCriterion($criterion) as $bridgeAction) {
            $this->bridgeActionRemove($bridgeAction);
        }
    }

    public function setBridgeClient(Client $bridgeClient)
    {
        $this->bridgeClient = $bridgeClient;
    }

    public function getBridgeClient()
    {
        return $this->bridgeClient;
    }

    public function incTrafficIn($inc)
    {
        $this->trafficIn += $inc;
    }

    public function resetTrafficIn()
    {
        $rv = $this->trafficIn;
        $this->trafficIn = 0;
        return $rv;
    }

    public function incTrafficOut($inc)
    {
        $this->trafficOut += $inc;
    }

    public function resetTrafficOut()
    {
        $rv = $this->trafficOut;
        $this->trafficOut = 0;
        return $rv;
    }

    public function setSslMsgCount($sslMsgCount)
    {
        $this->sslMsgCount = $sslMsgCount;
    }

    public function getSslMsgCount()
    {
        return $this->sslMsgCount;
    }

    /**
     * @codeCoverageIgnore
     */
    public function run()
    {
    }

    public function checkActions()
    {
        $action = $this->actionGetByCriterion(ClientAction::CRITERION_AFTER_PREVIOUS_ACTIONS);

        if ($action && count($this->actions)) {
            $actions = $this->actions;
            $caction = array_shift($actions);
            if ($caction->getId() == $action->getId()) {
                $this->log('debug', 'actions execute: 1');
                $this->log('debug', 'action execute: /' . $action->getName() . '/ /' . join(',', $action->getCriteria()) . '/');

                $this->actionRemove($action);
                $action->functionExec($this);

                $this->log('debug', 'actions left: ' . count($this->actions));
            }
        }
        /*if(!$this->actionsTime){
			$this->actionsTime = time();
		}
		if($this->actionsTime <= time() - static::ACTIONS_INTERVAL){
			$this->actionsTime = time();
			$this->log('debug', 'actions left: '.count($this->actions));
		}*/
    }

    public function checkSslPasswordTimeout()
    {
        if ($this->getStatus('hasSsl')) {
            if (!$this->sslPasswordTime) {
                $this->sslPasswordTime = time();
            }
            if ($this->sslPasswordTime < time() - static::SSL_PASSWORD_TTL || $this->sslMsgCount >= static::SSL_PASSWORD_MSG_MAX) {
                #$this->logColor('debug', 'SSL: password timed out: '.date('H:i:s', $this->sslPasswordTime), 'green');
                #$this->logColor('debug', 'SSL: msgs count: '.$this->sslMsgCount, 'green');

                $this->sslMsgCount = 0;
                $this->sslPasswordToken = '';
                $this->sslPasswordLocalNew = '';
                $this->sslPasswordPeerNew = '';
                $this->setStatus('hasSendReSslPasswortPut', false);
                $this->setStatus('hasReSslPasswortPut', false);
                $this->setStatus('hasReSslPasswortTest', false);

                $this->sendSslPasswordReput();
            }
        }
    }

    /**
     * @codeCoverageIgnore
     *
     * Only for testing.
     */
    public function dataRecv($data = null)
    {
        $this->incTrafficIn(strlen($data));

        $dataRecvReturnValue = '';
        do {
            $separatorPos = strpos($data, static::MSG_SEPARATOR);
            if ($separatorPos === false) {
                $this->recvBufferTmp .= $data;
                $data = '';
            } else {
                $msg = $this->recvBufferTmp . substr($data, 0, $separatorPos);
                $this->recvBufferTmp = '';

                $msg = base64_decode($msg);

                $msgHandleReturnValue = $this->msgHandleRaw($msg);
                $dataRecvReturnValue .= $msgHandleReturnValue;

                $data = substr($data, $separatorPos + 1);
            }
        } while ($data);

        return $dataRecvReturnValue;
    }

    /**
     * @codeCoverageIgnore
     */
    public function dataSend($data)
    {
        $msg = '';
        if ($data) {
            $data = base64_encode($data);
            $this->incTrafficOut(strlen($data) + static::MSG_SEPARATOR_LEN);
            $msg = $data . static::MSG_SEPARATOR;
        }
        return $msg;
    }

    protected function msgHandleEncode($msgRaw)
    {
        $msg = json_decode($msgRaw, true);

        $msgName = '';
        $msgData = [];

        if ($msg) {
            $msgName = substr(strtolower($msg['name']), 0, 256);
            if (array_key_exists('data', $msg)) {
                $msgData = $msg['data'];
            }
        } else {
            #$this->log('error', 'json_decode failed: "'.$msgRaw.'"');
            $this->log('error', 'json_decode failed: /' . base64_decode($msgRaw) . '/');
            #$this->log('error', 'json_decode failed');
        }

        return [$msgName, $msgData];
    }

    public function msgHandleRaw($msgRaw)
    {
        list($msgName, $msgData) = $this->msgHandleEncode($msgRaw);

        return $this->msgHandle($msgName, $msgData);
    }

    public function msgHandle($msgName, $msgData)
    {
        #fwrite(STDOUT, 'msgHandle: /'.$msgRaw.'/'."\n");

        $msgHandleReturnValue = '';
        if ($msgName == 'noop') {
            $this->log('debug', 'no operation');
        } elseif ($msgName == 'test') {
            $len = 0;
            $test_data = 'N/A';
            if (array_key_exists('len', $msgData)) {
                $len = (int)$msgData['len'];
            }
            if (array_key_exists('test_data', $msgData)) {
                $test_data = $msgData['test_data'];
            }

            $this->log('debug', 'test: ' . $len . ' /' . $test_data . '/');
        } elseif ($msgName == 'hello') {
            if (array_key_exists('ip', $msgData)) {
                $ip = $msgData['ip'];
                if ($ip != '127.0.0.1' && strIsIp($ip)) {
                    $this->getSettings()->data['node']['uriPub'] = 'tcp://' . $ip;
                    $this->getSettings()->setDataChanged(true);
                }
            }

            $this->log('debug', 'actions execute: CRITERION_AFTER_HELLO');
            $this->actionsExecute(ClientAction::CRITERION_AFTER_HELLO);

            $msgHandleReturnValue .= $this->sendId();
        } elseif ($msgName == 'id') {
            if ($this->getTable()) {
                if (!$this->getStatus('hasId')) {
                    $release = 0;
                    $id = '';
                    $port = 0;
                    $strKeyPub = '';
                    $strKeyPubSign = '';
                    $strKeyPubFingerprint = '';
                    $bridgeServer = false;
                    $bridgeClient = false;
                    $isChannelPeer = false;
                    $hashcash = '';
                    if (array_key_exists('release', $msgData)) {
                        $release = (int)$msgData['release'];
                    }
                    if (array_key_exists('id', $msgData)) {
                        $id = $msgData['id'];
                    }
                    if (array_key_exists('port', $msgData)) {
                        $port = (int)$msgData['port'];
                    }
                    if (array_key_exists('sslKeyPub', $msgData)) {
                        $strKeyPub = base64_decode($msgData['sslKeyPub']);
                    }
                    if (array_key_exists('sslKeyPubSign', $msgData)) {
                        $strKeyPubSign = base64_decode($msgData['sslKeyPubSign']);
                    }
                    if (array_key_exists('bridgeServer', $msgData)) {
                        $bridgeServer = (bool)$msgData['bridgeServer'];
                    }
                    if (array_key_exists('bridgeClient', $msgData)) {
                        $bridgeClient = (bool)$msgData['bridgeClient'];
                    }
                    if (array_key_exists('isChannel', $msgData)) { // isChannelPeer
                        $isChannelPeer = (bool)$msgData['isChannel'];
                    }

                    if ($isChannelPeer) {
                        $this->setStatus('isChannelPeer', true);
                    }

                    $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': /' . $id . '/ /' . $port . '/ bs=' . (int)$bridgeServer . '');

                    $idOk = false;
                    $node = new Node();

                    if (Uuid::isValid($id) && $id != Uuid::NIL) {
                        $node->setIdHexStr($id);
                        $node->setUri('tcp://' . $this->getUri()->getHost() . ':' . $port);
                        $node->setBridgeServer($bridgeServer);
                        $node->setBridgeClient($bridgeClient);
                        $node->setTimeLastSeen(time());

                        $node = $this->getTable()->nodeEnclose($node);

                        #$this->log('debug', 'node ok: '.(int)is_object($node).' /'.$node->getIdHexStr().'/');

                        // Check if not Local Node
                        if (!$this->getLocalNode()->isEqual($node)) {

                            if ($strKeyPub) {
                                if ($strKeyPubSign) {
                                    if (openssl_verify($strKeyPub, $strKeyPubSign, $strKeyPub, OPENSSL_ALGO_SHA1)) {
                                        if (Node::genIdHexStr($strKeyPub) == $id) {

                                            // Check if a public key already exists.
                                            if ($node->getSslKeyPub()) {
                                                #$this->logColor('debug', 'SSL public key ok [Aa]', 'green');
                                                $idOk = true;

                                                if ($node->getSslKeyPub() == $strKeyPub) {
                                                    #$this->logColor('debug', 'SSL public key ok [Ab]', 'green');
                                                    $node->setSslKeyPubStatus('C');
                                                    $node->setDataChanged(true);
                                                }
                                            } else {
                                                // No public key found.
                                                $sslPubKey = openssl_pkey_get_public($strKeyPub);
                                                if ($sslPubKey !== false) {
                                                    $sslPubKeyDetails = openssl_pkey_get_details($sslPubKey);

                                                    if ($sslPubKeyDetails['bits'] >= Node::SSL_KEY_LEN_MIN) {
                                                        #$this->logColor('debug', 'SSL public key ok [B]', 'green');
                                                        $idOk = true;

                                                        $node->setSslKeyPub($strKeyPub);
                                                        $node->setSslKeyPubStatus('C');
                                                        $node->setDataChanged(true);
                                                    } else {
                                                        $msgHandleReturnValue .= $this->sendError(2020, $msgName);
                                                        $this->log('error', static::getErrorMsg(2020));
                                                    }
                                                } else {
                                                    $msgHandleReturnValue .= $this->sendError(2040, $msgName);
                                                    $this->log('error', static::getErrorMsg(2040));
                                                }
                                            }
                                        } else {
                                            $msgHandleReturnValue .= $this->sendError(1030, $msgName);
                                            $this->log('error', static::getErrorMsg(1030));
                                        }
                                    } else {
                                        $msgHandleReturnValue .= $this->sendError(2080, $msgName);
                                        $this->log('error', static::getErrorMsg(2080));
                                        while ($openSslErrorStr = openssl_error_string()) {
                                            $this->log('error', 'SSL: ' . $openSslErrorStr);
                                        }
                                    }
                                } else {
                                    $msgHandleReturnValue .= $this->sendError(2005, $msgName);
                                    $this->log('error', static::getErrorMsg(2005));
                                }
                            } else {
                                $msgHandleReturnValue .= $this->sendError(2000, $msgName);
                                $this->log('error', static::getErrorMsg(2000));
                            }
                        } else {
                            // It's the ID from the Local Node. Something is wrong.
                            $msgHandleReturnValue .= $this->sendError(1020, $msgName);
                            $this->log('error', static::getErrorMsg(1020));
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                        $this->log('error', static::getErrorMsg(9000));
                    }

                    if ($idOk) {
                        $this->setStatus('hasId', true);
                        $this->setNode($node);

                        if ($this->getStatus('isOutbound')) {
                            $this->getNode()->incConnectionsOutboundSucceed();
                        }
                        if ($this->getStatus('isInbound')) {
                            $this->getNode()->incConnectionsInboundSucceed();
                        }

                        if (!$this->debug && $node->getBridgeServer() && $this->getStatus('bridgeTargetUri')) {
                            $this->logColor('debug', 'bridge server: ' . $this->getStatus('bridgeServerUri'), 'yellow');
                            $this->logColor('debug', 'bridge target: ' . $this->getStatus('bridgeTargetUri'), 'yellow');

                            $actions = [];

                            $action = new ClientAction(ClientAction::CRITERION_AFTER_ID_SUCCESSFULL);
                            $action->setName('bridge_server_init_ssl');
                            $action->functionSet(function ($action, $client) {
                                $this->logColor('debug', 'init ssl because of bridge server', 'green');
                                $client->sendSslInit();
                            });
                            $actions[] = $action;

                            $action = new ClientAction(ClientAction::CRITERION_AFTER_HAS_SSL);
                            $action->setName('bridge_server_send_connect');
                            $action->functionSet(function ($action, $client) {
                                $this->logColor('debug', 'bridge ssl ok', 'yellow');
                                $client->sendBridgeConnect($client->getStatus('bridgeTargetUri'));
                            });
                            $actions[] = $action;

                            $this->actionsAdd($actions);
                        }

                        $msgHandleReturnValue .= $this->sendIdOk();

                        $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ID OK');
                    } else {
                        $msgHandleReturnValue .= $this->sendQuit();
                        $this->shutdown();
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(1010, $msgName);
                    $this->log('error', static::getErrorMsg(1010));
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(9010, $msgName);
                $this->log('error', static::getErrorMsg(9010));
            }
        } elseif ($msgName == 'id_ok') {
            $this->log('debug', $this->getUri() . ' recv ' . $msgName);

            $this->log('debug', 'actions execute: CRITERION_AFTER_ID_SUCCESSFULL');
            $this->actionsExecute(ClientAction::CRITERION_AFTER_ID_SUCCESSFULL);

            if ($this->getStatus('isChannelPeer')) {
                $this->consoleMsgAdd('New incoming channel connection from ' . $this->getUri() . '.', true, true, true);
            }

            if ($this->getStatus('isChannelPeer') || $this->getStatus('isChannelLocal')) {
                if ($this->getServer() && $this->getServer()->getKernel()) {
                    $contact = $this->getServer()->getKernel()->getAddressbook()->contactGetByNodeId($this->getNode()->getIdHexStr());
                    if ($contact) {
                        $text = 'You talked to ';
                        $text .= $this->getNode()->getIdHexStr() . ' (' . $contact->getUserNickname() . ')';
                        $text .= ' once before.';
                        $this->consoleMsgAdd($text, true, false);
                    } else {
                        $this->consoleMsgAdd('You never talked to ' . $this->getNode()->getIdHexStr() . ' before.', true, false);
                        $this->consoleMsgAdd('Verify the public keys with you conversation partner on another channel.', true, false);
                        $this->consoleMsgAdd('Public keys fingerprints:', true, false);
                        $this->consoleMsgAdd('  Yours: ' . $this->getLocalNode()->getSslKeyPubFingerprint(), true, false);
                        $this->consoleMsgAdd('  Peers: ' . $this->getNode()->getSslKeyPubFingerprint(), true, false);
                    }
                }
            }
        } elseif ($msgName == 'node_find') {
            if ($this->getStatus('hasId')) {
                $rid = '';
                $num = static::NODE_FIND_NUM;
                $nodeId = '';
                $hashcash = '';
                if (array_key_exists('rid', $msgData)) {
                    $rid = $msgData['rid'];
                }
                if (array_key_exists('num', $msgData)) {
                    $num = $msgData['num'];
                }
                if (array_key_exists('nodeId', $msgData)) {
                    $nodeId = $msgData['nodeId'];
                }
                if (array_key_exists('hashcash', $msgData)) {
                    $hashcash = $msgData['hashcash'];
                }

                $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $rid);

                if ($rid) {
                    if ($hashcash && $this->hashcashVerify($hashcash, $this->getNode()->getIdHexStr(), static::HASHCASH_BITS_MIN)) {
                        if ($nodeId) {
                            $node = new Node();
                            $node->setIdHexStr($nodeId);

                            if ($node->isEqual($this->getLocalNode())) {
                                $this->log('debug', 'node find: find myself');

                                $msgHandleReturnValue .= $this->sendNodeFound($rid);
                            } elseif (!$node->isEqual($this->getNode()) && $onode = $this->getTable()->nodeFind($node)) {
                                $this->log('debug', 'node find: find in table');

                                $msgHandleReturnValue .= $this->sendNodeFound($rid, [$onode]);
                            } else {
                                $this->log('debug', 'node find: closest to "' . $node->getIdHexStr() . '"');

                                $nodes = $this->getTable()->nodeFindClosest($node, $num);
                                foreach ($nodes as $cnodeId => $cnode) {
                                    if ($cnode->isEqual($this->getNode())) {
                                        unset($nodes[$cnodeId]);
                                        break;
                                    }
                                }

                                $msgHandleReturnValue .= $this->sendNodeFound($rid, $nodes);
                            }
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(4000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    $this->log('error', static::getErrorMsg(9000));
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(1000, $msgName);
            }
        } elseif ($msgName == 'node_found') {
            if ($this->getStatus('hasId')) {
                $rid = '';
                $nodes = [];
                $hashcash = '';
                if (array_key_exists('rid', $msgData)) {
                    $rid = $msgData['rid'];
                }
                if (array_key_exists('nodes', $msgData)) {
                    $nodes = $msgData['nodes'];
                }
                if (array_key_exists('hashcash', $msgData)) {
                    $hashcash = $msgData['hashcash'];
                }

                if ($rid) {
                    $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $rid);

                    $request = $this->requestGetByRid($rid);
                    if ($request) {
                        if ($hashcash && $this->hashcashVerify($hashcash, $this->getNode()->getIdHexStr(), static::HASHCASH_BITS_MAX)) {
                            $this->requestRemove($request);

                            $nodeId = $request['data']['nodeId'];
                            $nodesFoundIds = $request['data']['nodesFoundIds'];
                            $distanceOld = $request['data']['distance'];
                            $uri = '';

                            $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $rid . ' nodes: ' . count($nodes));

                            if ($nodes) {
                                // Find the smallest distance.
                                foreach ($nodes as $nodeArId => $nodeAr) {

                                    $nodeArId = '';
                                    $nodeArSslPubKey = '';
                                    $nodeArBridgeServer = false;
                                    $nodeArBridgeClient = false;
                                    $nodeArBridgeDst = [];

                                    $node = new Node();
                                    if (isset($nodeAr['id'])) {
                                        $nodeArId = $nodeAr['id'];
                                    }
                                    if (isset($nodeAr['uri'])) {
                                        $node->setUri($nodeAr['uri']);
                                    }
                                    if (isset($nodeAr['sslKeyPub']) && $nodeAr['sslKeyPub']) {
                                        $nodeArSslPubKey = base64_decode($nodeAr['sslKeyPub']);
                                    }
                                    if (isset($nodeAr['bridgeServer'])) {
                                        $nodeArBridgeServer = $nodeAr['bridgeServer'];
                                    }
                                    if (isset($nodeAr['bridgeClient'])) {
                                        $nodeArBridgeClient = $nodeAr['bridgeClient'];
                                    }
                                    if (isset($nodeAr['bridgeDst'])) {
                                        # TODO
                                        $nodeArBridgeDst = $nodeAr['bridgeDst'];
                                    }

                                    $node->setBridgeServer($nodeArBridgeServer);
                                    $node->setBridgeServer($nodeArBridgeClient);
                                    $node->setTimeLastSeen(time());

                                    $distanceNew = $this->getLocalNode()->distanceHexStr($node);

                                    $this->log('debug', 'node found: ' . $nodeArId . ', do=/' . $distanceOld . '/ dn=/' . $distanceNew . '/');

                                    if ($nodeArId) {
                                        $node->setIdHexStr($nodeArId);

                                        if ($nodeArSslPubKey) {
                                            if (Node::genIdHexStr($nodeArSslPubKey) == $nodeArId) {
                                                if ($node->setSslKeyPub($nodeArSslPubKey)) {
                                                    if (!$this->getLocalNode()->isEqual($node)) {
                                                        if (!in_array($node->getIdHexStr(), $nodesFoundIds)) {

                                                            $nodesFoundIds[] = $nodeAr['id'];
                                                            if (count($nodesFoundIds) > static::NODE_FIND_MAX_NODE_IDS) {
                                                                array_shift($nodesFoundIds);
                                                            }

                                                            if ($nodeAr['id'] == $nodeId) {
                                                                $this->log('debug', 'node found: find completed');
                                                                $uri = '';
                                                            } else {
                                                                if ($distanceOld != $distanceNew) {
                                                                    $distanceMin = Node::idMinHexStr($distanceOld, $distanceNew);
                                                                    if ($distanceMin == $distanceNew) { // Is smaller then $distanceOld.
                                                                        $distanceOld = $distanceNew;
                                                                        $uri = $node->getUri();
                                                                    }
                                                                }
                                                            }

                                                            $this->getTable()->nodeEnclose($node);
                                                        } else {
                                                            $this->log('debug', 'node found: already known');
                                                        }
                                                    } else {
                                                        $this->log('debug', 'node found: myself, node equal');
                                                    }
                                                } else {
                                                    $this->log('debug', 'node found: public key invalid');
                                                }
                                            } else {
                                                $this->log('debug', 'node found: ID does not match public key');
                                            }
                                        } else {
                                            $this->log('debug', 'node found: no public key set');
                                        }
                                    } else {
                                        $this->log('debug', 'node found: no node id set');
                                    }
                                }
                            }

                            $this->log('debug', 'actions execute: CRITERION_AFTER_NODE_FOUND');
                            $this->actionsExecute(ClientAction::CRITERION_AFTER_NODE_FOUND);

                            if ((string)$uri) {
                                // Further search at the nearest node.
                                $this->log('debug', 'node found: uri (' . (string)$uri . ') ok');

                                $clientActions = [];
                                $action = new ClientAction(ClientAction::CRITERION_AFTER_ID_SUCCESSFULL);
                                $action->functionSet(function ($action, $client)
                                use ($nodeId, $distanceOld, $nodesFoundIds) {
                                    $client->sendNodeFind($nodeId, $distanceOld, $nodesFoundIds);
                                });
                                $clientActions[] = $action;

                                $this->getServer()->connect($uri, $clientActions);
                            }
                        } else {
                            $msgHandleReturnValue .= $this->sendError(4000, $msgName);
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }

                    $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $rid . ' end');
                } else {
                    $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(1000, $msgName);
            }
        } elseif ($msgName == 'msg') {
            if ($this->getStatus('hasId')) {
                if ($this->getMsgDb()) {
                    $rid = '';
                    $version = 0;
                    $id = '';
                    $srcNodeId = '';
                    $srcSslKeyPub = '';
                    $dstNodeId = '';
                    $subject = '';
                    $body = '';
                    $password = '';
                    $checksum = '';
                    $relayCount = 0;
                    $timeCreated = 0;
                    $hashcash = '';
                    if (array_key_exists('rid', $msgData)) {
                        $rid = $msgData['rid'];
                    }
                    if (array_key_exists('version', $msgData)) {
                        $version = (int)$msgData['version'];
                    }
                    if (array_key_exists('id', $msgData)) {
                        $id = $msgData['id'];
                    }
                    if (array_key_exists('srcNodeId', $msgData)) {
                        $srcNodeId = $msgData['srcNodeId'];
                    }
                    if (array_key_exists('srcSslKeyPub', $msgData)) {
                        $srcSslKeyPub = base64_decode($msgData['srcSslKeyPub']);
                    }
                    if (array_key_exists('dstNodeId', $msgData)) {
                        $dstNodeId = $msgData['dstNodeId'];
                    }
                    if (array_key_exists('body', $msgData)) {
                        $body = $msgData['body'];
                    }
                    if (array_key_exists('password', $msgData)) {
                        $password = $msgData['password'];
                    }
                    if (array_key_exists('checksum', $msgData)) {
                        $checksum = $msgData['checksum'];
                    }
                    if (array_key_exists('relayCount', $msgData)) {
                        $relayCount = (int)$msgData['relayCount'];
                    }
                    if (array_key_exists('timeCreated', $msgData)) {
                        $timeCreated = (int)$msgData['timeCreated'];
                    }
                    if (array_key_exists('hashcash', $msgData)) {
                        $hashcash = $msgData['hashcash'];
                    }

                    $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $id);

                    #fwrite(STDOUT, __CLASS__.'->'.__FUNCTION__.' body: '.$body."\n");
                    #$this->log('debug', 'msg '.$id.' body: '.$body);

                    $status = 1; // New
                    if ($this->getMsgDb()->getMsgById($id)) {
                        $status = 2; // Reject
                    }

                    $srcNode = new Node();
                    $srcNode->setIdHexStr($srcNodeId);
                    $srcNode = $this->getTable()->nodeEnclose($srcNode);

                    #fwrite(STDOUT, __CLASS__.'->'.__FUNCTION__.': srcNode: '.$srcNode->getIdHexStr()."\n");
                    $this->log('debug', 'msg ' . $id . ' srcNode: ' . $srcNode->getIdHexStr());

                    if ($srcNode->getSslKeyPub()) {
                        if ($srcNode->getSslKeyPub() != $srcSslKeyPub) {
                            $status = 3; // Error
                        }
                    } else {
                        if (Node::genIdHexStr($srcSslKeyPub) == $srcNodeId) {
                            if ($srcNode->setSslKeyPub($srcSslKeyPub)) {
                                $srcNode->setDataChanged(true);
                            }
                        } else {
                            $status = 3; // Error
                        }
                    }

                    if ($hashcash && $this->hashcashVerify($hashcash, $this->getNode()->getIdHexStr(), static::HASHCASH_BITS_MAX)) {
                        $msgHandleReturnValue .= $this->sendMsgResponse($rid, $status);

                        if ($status == 1) {
                            $msg = new Message();
                            $msg->setVersion($version);
                            $msg->setId($id);
                            $msg->setRelayNodeId($this->getNode()->getIdHexStr());
                            $msg->setSrcNodeId($srcNodeId);
                            $msg->setSrcSslKeyPub($srcSslKeyPub);
                            $msg->setDstNodeId($dstNodeId);
                            $msg->setBody($body);
                            $msg->setPassword($password);
                            $msg->setChecksum($checksum);
                            $msg->setRelayCount($relayCount);
                            $msg->setEncryptionMode('D');
                            $msg->setStatus('U');
                            $msg->setTimeCreated($timeCreated);
                            $msg->setTimeReceived(time());

                            if ($msg->getDstNodeId() == $this->getLocalNode()->getIdHexStr()) {
                                $msg->setDstSslPubKey($this->getLocalNode()->getSslKeyPub());
                                $msg->setSsl($this->getSsl());

                                try {
                                    if ($msg->decrypt()) {
                                        #fwrite(STDOUT, 'msg '.$id.': decrypt ok'."\n");
                                        $this->log('debug', 'msg ' . $id . ' decrypt ok');

                                        if (!$msg->getIgnore()) {
                                            #fwrite(STDOUT, 'msg '.$id.': not ignore'."\n");
                                            $this->log('debug', 'msg ' . $id . ' not ignore');
                                            $this->log('debug', 'msg ' . $id . ' subject: ' . $msg->getSubject());
                                            $this->getServer()->imapMailAdd($msg);
                                            $this->getServer()->consoleMsgAdd('You got mail.', true, true, true);
                                        } else {
                                            #fwrite(STDOUT, 'msg '.$id.': ignore'."\n");
                                            $this->log('debug', 'msg ' . $id . ' ignore');
                                        }
                                    } else {
                                        #fwrite(STDOUT, 'msg '.$id.': decrypt failed B'."\n");
                                        $this->log('debug', 'msg ' . $id . ' decrypt failed B');
                                    }
                                } catch (Exception $e) {
                                    #fwrite(STDOUT, 'msg '.$id.': decrypt failed A: '.$e->getMessage()."\n");
                                    $this->log('debug', 'msg ' . $id . ' decrypt failed A: ' . $e->getMessage());
                                }
                            } else {
                                #fwrite(STDOUT, 'msg '.$id.': msg not for me'."\n");
                                $this->log('debug', 'msg ' . $id . ' not for me');
                            }

                            $this->getMsgDb()->msgAdd($msg); // Add all messages.
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(4000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(3090, $msgName);
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(1000, $msgName);
            }
        } elseif ($msgName == 'msg_response') {
            if ($this->getStatus('hasId')) {
                $rid = '';
                $status = 0;
                if (array_key_exists('rid', $msgData)) {
                    $rid = $msgData['rid'];
                }
                if (array_key_exists('status', $msgData)) {
                    $status = (int)$msgData['status'];
                }

                $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $rid . ', ' . $status);

                $request = $this->requestGetByRid($rid);
                if ($request) {
                    #ve($request);

                    $msg = $request['data']['msg'];
                    $msg->addSentNode($this->getNode()->getIdHexStr());
                    $msg->setStatus('S');
                    if ($this->getNode()->getIdHexStr() == $msg->getDstNodeId()) {
                        $msg->setStatus('D');
                    }

                    $this->log('debug', 'actions execute: CRITERION_AFTER_MSG_RESPONSE_SUCCESSFULL');
                    $this->actionsExecute(ClientAction::CRITERION_AFTER_MSG_RESPONSE_SUCCESSFULL);
                } else {
                    $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(1000, $msgName);
            }

            $this->log('debug', 'actions execute: CRITERION_AFTER_MSG_RESPONSE');
            $this->actionsExecute(ClientAction::CRITERION_AFTER_MSG_RESPONSE);
        } elseif ($msgName == 'ssl_init') {
            #ve($this);
            if ($this->getSsl()) {
                if ($this->getStatus('hasId')) {
                    if (!$this->getStatus('hasSslInit')) {
                        $rid = '';
                        $hashcash = '';
                        if (array_key_exists('rid', $msgData)) {
                            $rid = $msgData['rid'];
                        }
                        if (array_key_exists('hashcash', $msgData)) {
                            $hashcash = $msgData['hashcash'];
                        }

                        $this->logColor('debug', 'SSL: init A: ' . $rid, 'green');

                        if ($hashcash && $this->hashcashVerify($hashcash, $this->getNode()->getIdHexStr(), static::HASHCASH_BITS_MIN)) {
                            $this->logColor('debug', 'SSL: init B', 'green');

                            $this->setStatus('hasSslInit', true);
                            $msgHandleReturnValue .= $this->sendSslInit();
                            $msgHandleReturnValue .= $this->sendSslInitResponse($rid, 1);
                        } else {
                            $this->resetStatusSsl();
                            #$msgHandleReturnValue .= $this->sendError(4000, $msgName);
                            $msgHandleReturnValue .= $this->sendSslInitResponse($rid, 4000);
                        }
                    }
                } else {
                    $this->resetStatusSsl();
                    #$msgHandleReturnValue .= $this->sendError(1000, $msgName);
                    $msgHandleReturnValue .= $this->sendSslInitResponse(null, 1000);
                }
            } else {
                $this->resetStatusSsl();
                #$msgHandleReturnValue .= $this->sendError(3090, $msgName);
                $msgHandleReturnValue .= $this->sendSslInitResponse(null, 3090);
            }
        } elseif ($msgName == 'ssl_init_response') {
            $rid = '';
            $status = 0;
            if (array_key_exists('rid', $msgData)) {
                $rid = $msgData['rid'];
            }
            if (array_key_exists('status', $msgData)) {
                $status = $msgData['status'];
            }

            $this->logColor('debug', 'SSL: init response: ' . $rid . ' ' . $status, 'green');

            if ($status) {
                if ($status == 1) {
                    // Ok
                    if ($this->getStatus('hasSslInit') && !$this->getStatus('hasSslInitOk')) {
                        $this->logColor('debug', 'SSL: init ok', 'green');

                        $this->setStatus('hasSslInitOk', true);
                        $msgHandleReturnValue .= $this->sendSslTest();
                    } else {
                        $this->logColor('warning', $msgName . ' SSL: you already initialized ssl', 'green');
                        $msgHandleReturnValue .= $this->sendError(2050, $msgName);
                    }
                } else {
                    $this->logColor('warning', $msgName . ' SSL: failed. status = ' . $status, 'green');
                    $this->resetStatusSsl();
                    $msgHandleReturnValue .= $this->sendError(3100, $msgName);
                }
            } else {
                $this->logColor('warning', $msgName . ' SSL: failed, invalid data', 'green');
                $this->resetStatusSsl();
                $msgHandleReturnValue .= $this->sendError(3100, $msgName);
            }
        } elseif ($msgName == 'ssl_test') {
            if ($this->getStatus('hasSslInitOk') && !$this->getStatus('hasSslTest')) {
                $msgData = $this->sslMsgDataPrivateDecrypt($msgData);
                if ($msgData) {
                    $token = '';
                    if (array_key_exists('token', $msgData)) {
                        $token = $msgData['token'];
                    }

                    if ($token) {
                        $this->logColor('debug', 'SSL: test', 'green');

                        $this->setStatus('hasSslTest', true);
                        $msgHandleReturnValue .= $this->sendSslVerify($token);
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                    $this->logColor('warning', $msgName . ' SSL: decryption failed', 'green');
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $logTmp = 'you need to initialize ssl /' . (int)$this->getStatus('hasSslInitOk') . '/';
                $logTmp .= ' /' . (int)$this->getStatus('hasSslTest') . '/';
                $this->logColor('warning', $msgName . ' SSL: ' . $logTmp, 'green');
            }
        } elseif ($msgName == 'ssl_verify') {
            if ($this->getStatus('hasSslTest') && !$this->getStatus('hasSslVerify')) {
                $msgData = $this->sslMsgDataPrivateDecrypt($msgData);
                if ($msgData) {
                    $token = '';
                    if (array_key_exists('token', $msgData)) {
                        $token = $msgData['token'];
                    }

                    if ($token && $this->sslTestToken && $token == $this->sslTestToken) {
                        $this->logColor('debug', 'SSL: verified', 'green');

                        $this->setStatus('hasSslVerify', true);
                        $msgHandleReturnValue .= $this->sendSslPasswordPut();
                    } else {
                        $msgHandleReturnValue .= $this->sendError(2080, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                    $this->logColor('warning', $msgName . ' SSL: decryption failed', 'green');
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $this->logColor('warning', $msgName . ' SSL: you need to initialize ssl', 'green');
            }
        } elseif ($msgName == 'ssl_password_put') {
            if ($this->getStatus('hasSslVerify') && !$this->getStatus('hasSslPasswortPut')) {
                $msgData = $this->sslMsgDataPrivateDecrypt($msgData);
                if ($msgData) {
                    $password = '';
                    if (array_key_exists('password', $msgData)) {
                        $password = $msgData['password'];
                    }

                    if ($password) {
                        $this->logColor('debug', 'SSL: password put', 'green');

                        $this->setStatus('hasSslPasswortPut', true);
                        $this->sslPasswordPeerCurrent = $password;
                        #$this->logColor('debug', 'SSL: peer password: '.substr($this->sslPasswordPeerCurrent, 0, 20), 'green');

                        $msgHandleReturnValue .= $this->sendSslPasswordTest();
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                    $this->logColor('warning', $msgName . ' SSL: decryption failed', 'green');
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $this->logColor('warning', $msgName . ' SSL: you need to initialize ssl', 'green');
            }
        } elseif ($msgName == 'ssl_password_test') {
            if ($this->getStatus('hasSslPasswortPut') && !$this->getStatus('hasSslPasswortTest')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                if ($msgData) {
                    $token = '';
                    if (array_key_exists('token', $msgData)) {
                        $token = $msgData['token'];
                    }

                    if ($token) {
                        $this->setStatus('hasSslPasswortTest', true);
                        $msgHandleReturnValue .= $this->sendSslPasswordVerify($token);
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                    $this->logColor('warning', $msgName . ' SSL: decryption failed', 'green');
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $this->logColor('warning', $msgName . ' SSL: you need to initialize ssl', 'green');
            }
        } elseif ($msgName == 'ssl_password_verify') {
            if ($this->getStatus('hasSslPasswortTest')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                if ($msgData) {
                    $token = '';
                    if (array_key_exists('token', $msgData)) {
                        $token = $msgData['token'];
                    }

                    #print __CLASS__.'->'.__FUNCTION__.': '.$msgName.' SSL: password token: '.$token."\n";

                    if ($token) {
                        $testToken = hash('sha512',
                            $this->sslPasswordToken . '_' . $this->getNode()->getIdHexStr());
                        if ($this->sslPasswordToken && $token == $testToken) {
                            $this->logColor('debug', 'SSL: password verified', 'green');
                            $this->logColor('debug', 'SSL: OK', 'green');

                            $this->setStatus('hasSsl', true);

                            $this->log('debug', 'actions execute: CRITERION_AFTER_HAS_SSL');
                            $this->actionsExecute(ClientAction::CRITERION_AFTER_HAS_SSL);
                        } else {
                            $msgHandleReturnValue .= $this->sendError(2090, $msgName);
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                    $this->logColor('warning', $msgName . ' SSL: decryption failed', 'green');
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $this->logColor('warning', $msgName . ' SSL: you need to initialize ssl', 'green');
            }

            $this->sslTestToken = '';
            $this->sslPasswordToken = '';
        } elseif ($msgName == 'ssl_password_reput') {
            if ($this->getStatus('hasSsl')) {
                if (!$this->getStatus('hasReSslPasswortPut')) {
                    $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                    if ($msgData) {
                        $password = '';
                        if (array_key_exists('password', $msgData)) {
                            $password = $msgData['password'];
                        }

                        if ($password) {
                            $this->logColor('debug', 're-SSL: password reput 1A', 'green');

                            $this->setStatus('hasReSslPasswortPut', true);
                            $this->sslPasswordPeerNew = $password;

                            #$this->logColor('debug', 'SSL: peer password: '.substr($this->sslPasswordPeerCurrent, 0, 20), 'green');
                            #$this->logColor('debug', 'SSL: peer password new: '.substr($this->sslPasswordPeerNew, 0, 20), 'green');

                            if (!$this->getStatus('hasSendReSslPasswortPut')) {
                                $this->sslMsgCount = 0;
                                $this->sslPasswordToken = '';
                                $this->sslPasswordLocalNew = '';
                                #$this->sslPasswordPeerNew = '';
                                #$this->setStatus('hasSendReSslPasswortPut', true);
                                #$this->setStatus('hasReSslPasswortTest', false);

                                $this->logColor('debug', 're-SSL: password reput 1B', 'green');
                                $msgHandleReturnValue .= $this->sendSslPasswordReput();
                            }

                            $this->logColor('debug', 're-SSL: password reput 2', 'green');
                            $msgHandleReturnValue .= $this->sendSslPasswordRetest();
                        } else {
                            $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                        $this->logColor('warning', $msgName . ' re-SSL: decryption failed', 'green');
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                    $this->log('warning', $msgName . ' re-SSL: decryption failed');
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $logMsg = $msgName . ' re-SSL: you need to initialize ssl, ';
                $logMsg .= 'hasSsl=/' . (int)$this->getStatus('hasSsl') . '/ ';
                $logMsg .= 'hasReSslPasswortPut=/' . (int)$this->getStatus('hasReSslPasswortPut') . '/';
                $this->logColor('warning', $logMsg, 'green');
            }
        } elseif ($msgName == 'ssl_password_retest') {
            if ($this->getStatus('hasReSslPasswortPut') && !$this->getStatus('hasReSslPasswortTest')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData, $this->sslPasswordLocalNew, $this->sslPasswordPeerNew);
                if ($msgData) {
                    $token = '';
                    if (array_key_exists('token', $msgData)) {
                        $token = $msgData['token'];
                    }

                    if ($token) {
                        $this->logColor('debug', 're-SSL: password retest', 'green');

                        $this->setStatus('hasReSslPasswortTest', true);
                        $msgHandleReturnValue .= $this->sendSslPasswordReverify($token);
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                    $this->logColor('warning', $msgName . ' re-SSL: decryption failed', 'green');
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $logMsg = $msgName . ' re-SSL: you need to initialize ssl, ';
                $logMsg .= 'hasReSslPasswortPut=/' . (int)$this->getStatus('hasReSslPasswortPut') . '/ ';
                $logMsg .= 'hasReSslPasswortTest=/' . (int)$this->getStatus('hasReSslPasswortTest') . '/';
                $this->logColor('warning', $logMsg, 'green');
            }
        } elseif ($msgName == 'ssl_password_reverify') {
            if ($this->getStatus('hasReSslPasswortTest')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData, $this->sslPasswordLocalNew, $this->sslPasswordPeerNew);
                if ($msgData) {
                    $token = '';
                    if (array_key_exists('token', $msgData)) {
                        $token = $msgData['token'];
                    }

                    if ($token) {
                        $testToken = hash('sha512',
                            $this->sslPasswordToken . '_' . $this->getNode()->getSslKeyPubFingerprint());
                        if ($this->sslPasswordToken && $token == $testToken) {
                            $this->logColor('debug', 're-SSL: password verified', 'green');
                            $this->logColor('debug', 're-SSL: OK', 'green');

                            $this->setStatus('hasSendReSslPasswortPut', false);
                            $this->setStatus('hasReSslPasswortPut', false);
                            $this->setStatus('hasReSslPasswortTest', false);

                            $this->sslPasswordLocalCurrent = $this->sslPasswordLocalNew;
                            $this->sslPasswordPeerCurrent = $this->sslPasswordPeerNew;

                            $this->logColor('debug', 'actions execute: CRITERION_AFTER_HAS_RESSL', 'green');
                            $this->actionsExecute(ClientAction::CRITERION_AFTER_HAS_RESSL);
                        } else {
                            $msgHandleReturnValue .= $this->sendError(2090, $msgName);
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2070, $msgName);
                    $this->logColor('warning', $msgName . ' re-SSL: decryption failed', 'green');
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $logMsg = $msgName . ' re-SSL: you need to initialize ssl, ';
                $logMsg .= 'hasReSslPasswortTest=/' . (int)$this->getStatus('hasReSslPasswortTest') . '/';
                $this->logColor('warning', $logMsg, 'green');
            }

            $this->sslPasswordToken = '';
            $this->sslPasswordLocalNew = '';
            $this->sslPasswordLocalNew = '';
        } elseif ($msgName == 'talk_request') {
            if ($this->getStatus('hasSsl')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                if ($msgData) {
                    $rid = '';
                    $userNickname = '[unknown]';
                    $hashcash = '';
                    if (array_key_exists('rid', $msgData)) {
                        $rid = $msgData['rid'];
                    }
                    if (array_key_exists('userNickname', $msgData) && $msgData['userNickname']) {
                        $userNickname = $msgData['userNickname'];
                    }
                    if (array_key_exists('hashcash', $msgData)) {
                        $hashcash = $msgData['hashcash'];
                    }

                    $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $rid . ', ' . $userNickname);

                    if ($rid) {
                        if ($hashcash && $this->hashcashVerify($hashcash, $this->getNode()->getIdHexStr(), static::HASHCASH_BITS_MAX)) {
                            if ($this->getServer() && $this->getServer()->kernelHasConsole()) {
                                $this->setStatus('hasTalkRequest', true);
                                $this->consoleTalkRequestAdd($rid, $userNickname);
                            } else {
                                $msgHandleReturnValue .= $this->sendTalkResponse($rid, 4);
                                #$msgHandleReturnValue .= $this->sendQuit();
                                #$this->shutdown();

                                $action = new ClientAction(ClientAction::CRITERION_AFTER_PREVIOUS_ACTIONS);
                                $action->setName('talk_request_after_previous_actions_shutdown');
                                $action->functionSet(function ($action, $client) {
                                    $client->shutdown();
                                });
                                $this->actionAdd($action);
                            }
                        } else {
                            $msgHandleReturnValue .= $this->sendError(4000, $msgName);
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                $this->logColor('warning', $msgName . ' SSL: you need to initialize ssl', 'green');
            }
        } elseif ($msgName == 'talk_response') {
            if ($this->getStatus('hasSsl')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                if ($msgData) {
                    $rid = '';
                    $status = 0;
                    $userNickname = '[unknown]';
                    if (array_key_exists('rid', $msgData)) {
                        $rid = $msgData['rid'];
                    }
                    if (array_key_exists('status', $msgData)) {
                        $status = (int)$msgData['status'];
                    }
                    if (array_key_exists('userNickname', $msgData) && $msgData['userNickname']) {
                        $userNickname = $msgData['userNickname'];
                    }

                    $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $rid . ', ' . $status);

                    $request = $this->requestGetByRid($rid);
                    if ($request) {
                        $this->requestRemove($request);
                        $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': request ok (' . $status . ')');

                        //if($status == 0){} // Undefined
                        if ($status == 1) {
                            // Accepted
                            $this->setStatus('hasTalk', true);
                            $this->consoleMsgAdd('Talk request accepted.', true, false, true);
                            $this->consoleMsgAdd('Now talking to "' . $userNickname . '".', true, true);

                            $this->consoleSetModeChannel(true);
                            $this->consoleSetModeChannelClient($this);

                            if ($this->getServer() && $this->getServer()->getKernel()) {
                                // Add to addressbook.
                                $contact = new Contact();
                                $contact->setNodeId($this->getNode()->getIdHexStr());
                                $contact->setUserNickname($userNickname);

                                $this->getServer()->getKernel()->getAddressbook()->contactAdd($contact);
                            }
                        } elseif ($status == 2) {
                            // Declined
                            $this->consoleMsgAdd('Talk request declined.', true, true, true);
                        } elseif ($status == 3) {
                            // Timeout
                            $this->consoleMsgAdd('Talk request timed out.', true, true, true);
                        } elseif ($status == 4) {
                            // No console, standalone server.
                            $this->consoleMsgAdd($this->getUri() . ' has no user interface. Can\'t talk to you.', true, true, true);
                            #$msgHandleReturnValue .= $this->sendQuit();
                            #$this->shutdown();

                            $action = new ClientAction(ClientAction::CRITERION_AFTER_PREVIOUS_ACTIONS);
                            $action->setName('talk_request_after_previous_actions_shutdown');
                            $action->functionSet(function ($action, $client) {
                                $client->sendQuit();
                                $client->shutdown();
                            });
                            $this->actionAdd($action);

                            $this->log('debug', 'actions left: ' . count($this->actions));
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                }
            }
        } elseif ($msgName == 'talk_msg') {
            #$this->log('debug', $this->getUri().' recv '.$msgName);

            if ($this->getStatus('hasSsl')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                if ($msgData) {
                    $rid = '';
                    $userNickname = '[unknown]';
                    $text = '';
                    $ignore = false;
                    if (array_key_exists('rid', $msgData)) {
                        $rid = $msgData['rid'];
                    }
                    if (array_key_exists('userNickname', $msgData) && $msgData['userNickname']) {
                        $userNickname = $msgData['userNickname'];
                    }
                    if (array_key_exists('text', $msgData)) {
                        $text = $msgData['text'];
                    }
                    if (array_key_exists('ignore', $msgData)) {
                        $ignore = $msgData['ignore'];
                    }

                    #$debugText = '/'.$rid.'/ /'.$userNickname.'/ '.(int)$ignore.' /'.$text.'/';
                    #$this->log('debug', $this->getUri().' recv '.$msgName.': '.$debugText);
                    $this->log('debug', $this->getUri() . ' recv ' . $msgName);

                    if (!$ignore) {
                        $this->consoleTalkMsgAdd($rid, $userNickname, $text);
                    }
                }
            }
        } elseif ($msgName == 'talk_user_nickname_change') {
            #$this->log('debug', $this->getUri().' recv '.$msgName);

            if ($this->getStatus('hasSsl')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                if ($msgData) {
                    $userNicknameOld = '[unknown]';
                    $userNicknameNew = '';
                    if (array_key_exists('userNicknameOld', $msgData) && $msgData['userNicknameOld']) {
                        $userNicknameOld = $msgData['userNicknameOld'];
                    }
                    if (array_key_exists('userNicknameNew', $msgData)) {
                        $userNicknameNew = $msgData['userNicknameNew'];
                    }

                    if ($this->getServer() && $this->getServer()->getKernel()) {
                        $contact = $this->getServer()->getKernel()->getAddressbook()->contactGetByNodeId($this->getNode()->getIdHexStr());
                        if ($contact) {
                            if (!$userNicknameOld) {
                                $userNicknameOld = $contact->getUserNickname();
                            }

                            if ($userNicknameNew) {
                                $contact->setUserNickname($userNicknameNew);
                                $this->getServer()->getKernel()->getAddressbook()->setDataChanged(true);
                            }
                        }
                    }

                    if ($userNicknameNew) {
                        #$this->log('debug', $this->getUri().' recv '.$msgName.': '.$userNicknameOld.', '.$userNicknameNew);
                        $this->consoleMsgAdd('User "' . $userNicknameOld . '" is now known as "' . $userNicknameNew . '".',
                            true, true, true);
                    }
                }
            }
        } elseif ($msgName == 'talk_close') {
            if ($this->getStatus('hasSsl')) {
                $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                if ($msgData) {
                    $rid = '';
                    $userNickname = '[unknown]';
                    if (array_key_exists('rid', $msgData)) {
                        $rid = $msgData['rid'];
                    }
                    if (array_key_exists('userNickname', $msgData) && $msgData['userNickname']) {
                        $userNickname = $msgData['userNickname'];
                    }

                    $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $rid . ', ' . $userNickname);

                    $msgHandleReturnValue .= $this->sendQuit();
                    $this->setStatus('hasTalkClose', true);
                    $this->shutdown();

                    $this->consoleMsgAdd('Talk closed by "' . $userNickname . '".', true, true, true);
                    $this->consoleSetModeChannel(false);
                    $this->consoleSetModeChannelClient(null);
                }
            }
        } /*elseif($msgName == 'bridge_subscribe'){
			if($this->getSettings()->data['node']['bridge']['server']['enabled']){
				if($this->getStatus('hasSsl')){
					$msgData = $this->sslMsgDataPasswordDecrypt($msgData);
					if($msgData){
						$rid = '';
						$subscribe = true;
						if(array_key_exists('rid', $msgData)){
							$rid = $msgData['rid'];
						}
						if(array_key_exists('subscribe', $msgData)){
							$subscribe = (bool)$msgData['subscribe'];
						}
						
						$this->logColor('debug', $this->getUri().' recv '.$msgName.': '.$rid.', '.(int)$subscribe, 'yellow');
						
						if($rid){
							$this->getNode()->setBridgeClient($subscribe);
							$this->getNode()->setBridgeSubscribed($subscribe);
							
							$msgHandleReturnValue .= $this->sendBridgeSubscribeResponse($rid);
						}
						else{
							$msgHandleReturnValue .= $this->sendError(9000, $msgName);
						}
					}
					else{
						$msgHandleReturnValue .= $this->sendError(9000, $msgName);
					}
				}
				else{
					$msgHandleReturnValue .= $this->sendError(2060, $msgName);
					$this->log('warning', static::getErrorMsg(2060));
				}
			}
			else{
				$msgHandleReturnValue .= $this->sendError(5000, $msgName);
				$this->log('warning', static::getErrorMsg(5000));
			}
		}
		elseif($msgName == 'bridge_subscribe_response'){
			if($this->getStatus('hasSsl')){
				$msgData = $this->sslMsgDataPasswordDecrypt($msgData);
				if($msgData){
					$rid = '';
					$status = 0;
					if(array_key_exists('rid', $msgData)){
						$rid = $msgData['rid'];
					}
					if(array_key_exists('status', $msgData)){
						$status = (int)$msgData['status'];
					}
					
					$this->log('debug', $this->getUri().' recv '.$msgName.': '.$rid.', '.$status);
				}
				else{
					$msgHandleReturnValue .= $this->sendError(9000, $msgName);
				}
			}
			else{
				$msgHandleReturnValue .= $this->sendError(2060, $msgName);
				$this->log('warning', static::getErrorMsg(2060));
			}
		}*/
        elseif ($msgName == 'bridge_connect') {
            if ($this->getSettings()->data['node']['bridge']['server']['enabled']) {
                if ($this->getStatus('hasSsl')) {
                    $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                    if ($msgData) {
                        $targetUri = '';
                        if (array_key_exists('uri', $msgData)) {
                            $targetUri = $msgData['uri'];
                            $targetUri = UriFactory::factory($targetUri);
                        }

                        $this->logColor('debug', $this->getUri() . ' recv ' . $msgName . ': target /' . $targetUri . '/', 'yellow');

                        $client = $this->getServer()->connect($targetUri);
                        if ($client !== null) {
                            $this->logColor('debug', 'bridge connected to target /' . $targetUri . '/', 'yellow');

                            $this->setBridgeClient($client);
                            $client->setBridgeClient($client);
                            $client->setStatus('bridgeTargetUri', $targetUri);

                            $msgHandleReturnValue .= $this->sendBridgeConnectResponse(1);
                        } else {
                            $this->logColor('debug', 'bridge connection to target /' . $targetUri . '/ failed', 'yellow');
                            $msgHandleReturnValue .= $this->sendBridgeConnectResponse(2);
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                    $this->log('warning', static::getErrorMsg(2060));
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(5000, $msgName);
                $this->log('warning', static::getErrorMsg(5000));
            }
        } elseif ($msgName == 'bridge_connect_response') {
            if ($this->getSettings()->data['node']['bridge']['server']['enabled'] ||
                $this->getSettings()->data['node']['bridge']['client']['enabled']
            ) {
                if ($this->getStatus('hasSsl')) {
                    $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                    if ($msgData) {
                        $status = 0;
                        if (array_key_exists('status', $msgData)) {
                            $status = (int)$msgData['status'];
                        }

                        $this->logColor('debug', $this->getUri() . ' recv ' . $msgName . ': /' . $status . '/', 'yellow');
                        if ($status) {
                            if ($status == 1) {
                                $this->logColor('debug', 'bridge connection ok', 'yellow');

                                # TODO
                                #$this->bridgeActionRemoveByCriterion(ClientAction::CRITERION_AFTER_HELLO);
                                #$this->bridgeActionRemoveByCriterion(ClientAction::CRITERION_AFTER_ID_SUCCESSFULL);
                                #$this->bridgeActionsExecute(ClientAction::CRITERION_AFTER_HAS_SSL);
                                /*
								$msgHandleReturnValue .= $this->sendBridgeMsg('my_data');
									$name = $bridgeAction->getName();
									$criteria = join(',', $bridgeAction->getCriteria());
									$this->logColor('debug', 'bridgeAction: /'.$name.'/ /'.$criteria.'/', 'yellow');
									#$this->bridgeActionRemove($bridgeAction);
									#$bridgeAction->functionExec($this);
								}*/
                            } elseif ($status == 2) {
                                $this->logColor('debug', 'bridge connection failed', 'yellow');
                            } else {
                                $this->logColor('debug', 'bridge connect response unknown status', 'yellow');
                            }
                        } else {
                            $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                    $this->log('warning', static::getErrorMsg(2060));
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(5100, $msgName);
                $this->log('warning', static::getErrorMsg(5100));
            }
        } elseif ($msgName == 'bridge_msg') {
            if ($this->getSettings()->data['node']['bridge']['server']['enabled']) {
                if ($this->getStatus('hasSsl')) {
                    $msgData = $this->sslMsgDataPasswordDecrypt($msgData);
                    if ($msgData) {
                        $data = '';
                        if (array_key_exists('data', $msgData)) {
                            $data = $msgData['data'];
                        }

                        $this->logColor('debug', $this->getUri() . ' recv ' . $msgName . ': /' . $data . '/', 'yellow');
                        $this->logColor('debug', 'bridge server: ' . $this->getStatus('bridgeServerUri'), 'yellow');
                        $this->logColor('debug', 'bridge target: ' . $this->getStatus('bridgeTargetUri'), 'yellow');
                        $this->logColor('debug', 'bridge client: ' . (int)($this->bridgeClient !== null), 'yellow');

                        // @TODO bridgeClient
                        if ($this->bridgeClient) {
                        }
                    } else {
                        $msgHandleReturnValue .= $this->sendError(9000, $msgName);
                    }
                } else {
                    $msgHandleReturnValue .= $this->sendError(2060, $msgName);
                    $this->log('warning', static::getErrorMsg(2060));
                }
            } else {
                $msgHandleReturnValue .= $this->sendError(5200, $msgName);
                $this->log('warning', static::getErrorMsg(5200));
            }
        } elseif ($msgName == 'ping') {
            $rid = '';
            if (array_key_exists('rid', $msgData)) {
                $rid = $msgData['rid'];
            }
            $msgHandleReturnValue .= $this->sendPong($rid);
        } elseif ($msgName == 'pong') {
            $rid = '';
            if (array_key_exists('rid', $msgData)) {
                $rid = $msgData['rid'];
            }
            $this->pongTime = time();
        } elseif ($msgName == 'error') {
            $code = 0;
            $msg = '';
            $name = '';
            if (array_key_exists('msg', $msgData)) {
                $code = (int)$msgData['code'];
            }
            if (array_key_exists('msg', $msgData)) {
                $msg = $msgData['msg'];
            }
            if (array_key_exists('msg', $msgData)) {
                $name = $msgData['name'];
            }

            if ($code >= 2000 && $code <= 3999) {
                // SSL
                $this->logColor('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $code . ', ' . $msg . ', ' . $name, 'green');
            } elseif ($code >= 5000 && $code <= 5999) {
                // Bridge
                $this->logColor('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $code . ', ' . $msg . ', ' . $name, 'yellow');
            } else {
                $this->log('debug', $this->getUri() . ' recv ' . $msgName . ': ' . $code . ', ' . $msg . ', ' . $name);
            }
        } elseif ($msgName == 'quit') {
            $this->shutdown();
        } else {
            $this->log('debug', $this->getUri() . ' recv /' . $msgName . '/: not implemented.');
            $msgHandleReturnValue .= $this->sendError(9020, $msgName);
        }

        return $msgHandleReturnValue;
    }

    public function msgCreate($name, $data = [])
    {
        $json = [
            'name' => $name,
        ];
        if ($data) {
            $json['data'] = $data;
        }
        return json_encode($json);
    }

    public function sendNoop()
    {
        return $this->dataSend($this->msgCreate('noop'));
    }

    public function sendTest()
    {
        $test_data = 'BEGIN_' . str_repeat('abcdef', 4096) . '_END';
        $len = strlen($test_data);
        $data = [
            'len' => $len,
            'test_data' => $test_data,
        ];
        return $this->dataSend($this->msgCreate('test', $data));
    }

    public function msgCreateHello()
    {
        $data = [
            'ip' => $this->getUri()->getHost(),
        ];
        return $this->msgCreate('hello', $data);
    }

    public function sendHello()
    {
        return $this->dataSend($this->msgCreateHello());
    }

    public function msgCreateId()
    {
        if (!$this->getLocalNode()) {
            throw new RuntimeException('msgCreateId: localNode not set.');
        }
        if (!$this->getSsl()) {
            throw new RuntimeException('msgCreateId: SSL not set.');
        }

        $sslKeyPub = $this->getLocalNode()->getSslKeyPub();
        $sslKeyPubBase64 = base64_encode($sslKeyPub);

        $sslKeyPubSign = '';
        if (openssl_sign($sslKeyPub, $sign, $this->getSsl(), OPENSSL_ALGO_SHA1)) {
            $sslKeyPubSign = base64_encode($sign);
        } else {
            $this->log('error', 'msgCreateId: openssl_sign failed');
            while ($openSslErrorStr = openssl_error_string()) {
                $this->log('error', 'SSL: ' . $openSslErrorStr);
            }
        }

        #$this->log('debug', 'msgCreateId sign: /'.$sslKeyPubSign.'/');

        if ($sslKeyPubSign) {
            $data = [
                'release' => PhpChat::RELEASE,
                'id' => $this->getLocalNode()->getIdHexStr(),
                'port' => $this->getLocalNode()->getUri()->getPort(),
                'sslKeyPub' => $sslKeyPubBase64,
                'sslKeyPubSign' => $sslKeyPubSign,
                'bridgeServer' => $this->getSettings()->data['node']['bridge']['server']['enabled'],
                'bridgeClient' => $this->getSettings()->data['node']['bridge']['client']['enabled'],

                'isChannel' => $this->getStatus('isChannelLocal'),
            ];
            return $this->msgCreate('id', $data);
        } else {
            return '';
        }
    }

    public function sendId()
    {
        return $this->dataSend($this->msgCreateId());
    }

    public function sendIdOk()
    {
        return $this->dataSend($this->msgCreate('id_ok'));
    }

    public function sendNodeFind($nodeId, $distance = null, $nodesFoundIds = null, $useHashcash = true)
    {
        if (!$this->getTable()) {
            throw new RuntimeException('table not set.');
        }
        if ($distance === null) {
            $distance = 'ffffffff-ffff-4fff-bfff-ffffffffffff';
        }
        if ($nodesFoundIds === null) {
            $nodesFoundIds = [];
        }

        $rid = (string)Uuid::uuid4();

        $this->log('debug', 'send node find: ' . $rid);

        $this->requestAdd('node_find', $rid, [
            'nodeId' => $nodeId,
            'distance' => $distance,
            'nodesFoundIds' => $nodesFoundIds,
        ]);

        $data = [
            'rid' => $rid,
            'num' => static::NODE_FIND_NUM,
            'nodeId' => $nodeId,
            'hashcash' => '',
        ];
        if ($useHashcash) {
            $data['hashcash'] = $this->hashcashMint(static::HASHCASH_BITS_MIN);
        }
        return $this->dataSend($this->msgCreate('node_find', $data));
    }

    public function sendNodeFound($rid, $nodes = [], $useHashcash = true)
    {
        if (!$this->getTable()) {
            throw new RuntimeException('table not set.');
        }

        $this->log('debug', 'send node found: ' . $rid);

        $nodesOut = [];
        foreach ($nodes as $nodeId => $node) {
            $nodeOut = [
                'id' => $node->getIdHexStr(),
                'uri' => '',
                'sslKeyPub' => base64_encode($node->getSslKeyPub()),
                'bridgeServer' => $node->getBridgeServer(),
                'bridgeClient' => $node->getBridgeClient(),
                'bridgeDst' => [],
            ];

            if (!$this->getSettings()->data['node']['bridge']['server']['enabled']
                && !$node->getBridgeClient()
            ) {
                $nodeOut['uri'] = (string)$node->getUri();
            }
            if ($node->getBridgeClient()) {
                # @TODO: alle exit bridges ins array eintragen
                #$nodeOut['bridgeDst'] = 
            }

            $nodesOut[] = $nodeOut;
        }

        $data = [
            'rid' => $rid,
            'nodes' => $nodesOut,
            'hashcash' => '',
        ];
        if ($useHashcash) {
            $data['hashcash'] = $this->hashcashMint(static::HASHCASH_BITS_MAX);
        }
        return $this->dataSend($this->msgCreate('node_found', $data));
    }

    public function sendMsg(Message $msg)
    {
        $rid = (string)Uuid::uuid4();

        $this->requestAdd('msg', $rid, [
            'msg' => $msg,
        ]);

        $data = [
            'rid' => $rid,

            'version' => $msg->getVersion(),
            'id' => $msg->getId(),
            'srcNodeId' => $msg->getSrcNodeId(),
            'srcSslKeyPub' => base64_encode($msg->getSrcSslKeyPub()),
            'dstNodeId' => $msg->getDstNodeId(),
            'body' => $msg->getBody(),
            'password' => $msg->getPassword(),
            'checksum' => $msg->getChecksum(),
            'relayCount' => (int)$msg->getRelayCount() + 1,
            'timeCreated' => (int)$msg->getTimeCreated(),
            'hashcash' => $this->hashcashMint(static::HASHCASH_BITS_MAX),
        ];
        return $this->dataSend($this->msgCreate('msg', $data));
    }

    private function sendMsgResponse($rid, $status)
    {
        $data = [
            'rid' => $rid,
            'status' => (int)$status,
        ];
        return $this->dataSend($this->msgCreate('msg_response', $data));
    }

    public function sendSslInit($useHashcash = true)
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        $logTmp = (int)$this->getStatus('hasSslInit') . ', ' . (int)$this->getStatus('hasSendSslInit');
        $this->logColor('debug', 'send SSL init A: ' . $logTmp, 'green');

        if ($this->getStatus('hasSendSslInit')) {
            $this->logColor('debug', 'send SSL init BB', 'green');
            return '';
        } else {
            $this->setStatus('hasSendSslInit', true);
            $this->log('debug', 'send SSL init: create data');

            $rid = (string)Uuid::uuid4();

            $this->logColor('debug', 'send SSL init BA: ' . $rid, 'green');

            $data = [
                'rid' => $rid,
                'hashcash' => '',
            ];
            if ($useHashcash) {
                $data['hashcash'] = $this->hashcashMint(static::HASHCASH_BITS_MIN);
            }
            return $this->dataSend($this->msgCreate('ssl_init', $data));
        }
    }

    private function sendSslInitResponse($rid, $status)
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        $this->logColor('debug', 'send SSL init response: ' . $rid . ' ' . $status, 'green');

        $data = [
            'rid' => $rid,
            'status' => $status,
        ];

        return $this->dataSend($this->msgCreate('ssl_init_response', $data));
    }

    private function sendSslTest()
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        $this->sslTestToken = (string)Uuid::uuid4();

        $data = [
            'token' => $this->sslTestToken,
        ];

        $this->logColor('debug', 'send SSL Test: ' . $this->sslTestToken, 'green');

        return $this->dataSend($this->sslMsgCreatePublicEncrypt('ssl_test', $data));
    }

    private function sendSslVerify($token)
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        $this->logColor('debug', 'send SSL verify', 'green');

        $data = [
            'token' => $token,
        ];
        return $this->dataSend($this->sslMsgCreatePublicEncrypt('ssl_verify', $data));
    }

    private function genSslPassword()
    {
        $password = hash('sha512', $this->getUri() . '_' . mt_rand(0, 999999));
        return $password;
    }

    private function sendSslPasswordPut()
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        $this->sslPasswordLocalCurrent = $this->genSslPassword();
        $this->sslPasswordTime = time();
        #$this->logColor('debug', 'SSL: local password: '.substr($this->sslPasswordLocalCurrent, 0, 20), 'green');
        $this->logColor('debug', 'send SSL password put', 'green');

        $data = [
            'password' => $this->sslPasswordLocalCurrent,
        ];
        return $this->dataSend($this->sslMsgCreatePublicEncrypt('ssl_password_put', $data));
    }

    private function sendSslPasswordTest()
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        $this->sslPasswordLocalNew = $this->genSslPassword();
        $this->sslPasswordTime = time();
        $this->logColor('debug', 'send SSL password test', 'green');

        $this->sslPasswordToken = (string)Uuid::uuid4();

        $data = [
            'token' => $this->sslPasswordToken,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('ssl_password_test', $data));
    }

    private function sendSslPasswordVerify($token)
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }
        $this->sslPasswordToken = (string)Uuid::uuid4();

        $this->logColor('debug', 'send SSL password verify', 'green');

        $token = hash('sha512', $token . '_' . $this->getLocalNode()->getIdHexStr());

        $data = [
            'token' => $token,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('ssl_password_verify', $data));
    }

    public function sendSslPasswordReput()
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        $this->setStatus('hasSendReSslPasswortPut', true);

        $this->sslPasswordLocalNew = $this->genSslPassword();
        $this->sslPasswordTime = time();
        #$this->log('debug', 're-SSL: local password:     '.substr($this->sslPasswordLocalCurrent, 0, 20));
        #$this->log('debug', 're-SSL: local password new: '.substr($this->sslPasswordLocalNew, 0, 20));
        #$this->log('debug', 're-SSL: peer password:      '.substr($this->sslPasswordPeerCurrent, 0, 20));
        #$this->log('debug', 're-SSL: peer password new:  '.substr($this->sslPasswordPeerNew, 0, 20));
        $this->logColor('debug', 'send SSL password reput', 'green');

        $data = [
            'password' => $this->sslPasswordLocalNew,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('ssl_password_reput', $data));
    }

    private function sendSslPasswordRetest()
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        $this->sslPasswordToken = (string)Uuid::uuid4();
        #$this->log('debug', 're-SSL token: '.substr($this->sslPasswordToken, 0, 20));
        $this->logColor('debug', 'send SSL password retest', 'green');

        $data = [
            'token' => $this->sslPasswordToken,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('ssl_password_retest',
            $data, $this->sslPasswordLocalNew, $this->sslPasswordPeerNew));
    }

    private function sendSslPasswordReverify($token)
    {
        if (!$this->getSsl()) {
            throw new RuntimeException('ssl not set.');
        }

        #$this->log('debug', 're-SSL peer token: '.substr($token, 0, 20));
        $token = hash('sha512', $token . '_' . $this->getLocalNode()->getSslKeyPubFingerprint());
        #$this->log('debug', 're-SSL local token: '.substr($token, 0, 20));
        $this->logColor('debug', 'send SSL password reverify', 'green');

        $data = [
            'token' => $token,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('ssl_password_reverify',
            $data, $this->sslPasswordLocalNew, $this->sslPasswordPeerNew));
    }

    public function sendTalkRequest($userNickname)
    {
        $rid = (string)Uuid::uuid4();

        $this->log('debug', 'send talk request: ' . $rid);

        $this->setStatus('hasTalkRequest', true);

        $this->requestAdd('talk_request', $rid, [
            'userNickname' => $userNickname,
        ]);

        $data = [
            'rid' => $rid,
            'userNickname' => $userNickname,
            'hashcash' => $this->hashcashMint(static::HASHCASH_BITS_MAX),
        ];

        #if($this->getSettings()->data['node']['bridge']['client']['enabled'] && $this->getNode()->getBridgeServer()){
        /*if($this->getBridgeClient()){
			$this->logColor('debug', 'bridge server connection: send talk request', 'yellow');
			$rv = $this->sendBridgeMsg('talk_request', $data);
			ve($rv);
			return $rv;
		}*/

        #$this->logColor('debug', 'bridge server: '.$this->getStatus('bridgeServerUri'), 'yellow');
        $this->logColor('debug', 'send talk request');
        #$this->logColor('debug', 'bridge target: '.$this->getStatus('bridgeTargetUri'), 'yellow');
        #$this->logColor('debug', 'bridge client: '.(int)($this->bridgeClient !== null), 'yellow');
        if ($this->getStatus('bridgeServerUri')) {
            $this->logColor('debug', 'send talk request over bridge', 'yellow');
            return $this->sendBridgeMsg($this->msgCreate('talk_request', $data));
        }
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('talk_request', $data));
    }

    public function sendTalkResponse($rid, $status, $userNickname = '')
    {
        if ($status == 1) {
            $this->setStatus('hasTalk', true);
        }

        $data = [
            'rid' => $rid,
            'status' => (int)$status,
            'userNickname' => $userNickname,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('talk_response', $data));
    }

    public function sendTalkMsg($rid, $userNickname, $text, $ignore)
    {
        $data = [
            'rid' => $rid,
            'userNickname' => $userNickname,
            'text' => $text,
            'ignore' => $ignore,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('talk_msg', $data));
    }

    public function sendTalkUserNicknameChange($userNicknameOld, $userNicknameNew)
    {
        $data = [
            'userNicknameOld' => $userNicknameOld,
            'userNicknameNew' => $userNicknameNew,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('talk_user_nickname_change', $data));
    }

    public function sendTalkClose($rid, $userNickname)
    {
        $data = [
            'rid' => $rid,
            'userNickname' => $userNickname,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('talk_close', $data));
    }

    /*public function sendBridgeSubscribe($subscribe = true){
		$rid = (string)Uuid::uuid4();
		
		$data = array(
			'rid' => $rid,
			'subscribe' => (bool)$subscribe,
		);
		
		$this->logColor('debug', 'send bridge_subscribe: '.$rid.', '.(int)$subscribe, 'yellow');
		
		return $this->dataSend($this->sslMsgCreatePasswordEncrypt('bridge_subscribe', $data));
	}
	
	public function sendBridgeSubscribeResponse($rid, $status = 1){
		$data = array(
			'rid' => $rid,
			'status' => (int)$status,
		);
		
		$this->logColor('debug', 'send bridge_subscribe_response: '.$rid.', '.$status, 'yellow');
		
		return $this->dataSend($this->sslMsgCreatePasswordEncrypt('bridge_subscribe_response', $data));
	}*/

    public function sendBridgeConnect($targetUri)
    {
        $this->logColor('debug', 'bridge target: ' . $targetUri, 'yellow');

        $data = [
            'uri' => (string)$targetUri,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('bridge_connect', $data));
    }

    public function sendBridgeConnectResponse($status)
    {
        /*
			1 = OK
			2 = failed
		*/
        $data = [
            'status' => (int)$status,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('bridge_connect_response', $data));
    }

    public function sendBridgeMsg($data)
    {
        $data = [
            'data' => $data,
        ];
        return $this->dataSend($this->sslMsgCreatePasswordEncrypt('bridge_msg', $data));
    }

    public function sendPing($rid = '')
    {
        $this->pingTime = time();

        $data = [
            'rid' => $rid,
        ];
        return $this->dataSend($this->msgCreate('ping', $data));
    }

    public function sendPong($rid = '')
    {
        $data = [
            'rid' => $rid,
        ];
        return $this->dataSend($this->msgCreate('pong', $data));
    }

    public static function getError()
    {
        $errors = [
            // 1000-1999: ID
            1000 => 'You need to identify',
            1010 => 'You already identified',
            1020 => 'You are using my ID',
            1030 => 'ID does not match public key',

            // 2000-3999: SSL
            2000 => 'SSL: no public key found',
            2005 => 'SSL: no signature found',
            2010 => 'SSL: you need a key with minimum length of ' . Node::SSL_KEY_LEN_MIN . ' bits',
            2020 => 'SSL: public key too short',
            2030 => 'SSL: public key changed since last handshake',
            2035 => 'SSL: public key already in table with different node id',
            2040 => 'SSL: invalid key',
            2050 => 'SSL: you already initialized ssl',
            2060 => 'SSL: you need to initialize ssl',
            2070 => 'SSL: decryption failed',
            2080 => 'SSL: verification failed',
            2090 => 'SSL: password verification failed',
            3090 => 'SSL: invalid setup',
            3100 => 'SSL: init failed',

            // 4000-4999: Hashcash
            4000 => 'Hashcash: verification failed',

            // 5000-5999: Bridge
            5000 => 'Bridge: no server',
            5100 => 'Bridge: no client',
            5200 => 'Bridge: no server or client',

            // 9000-9999: Misc
            9000 => 'Invalid data',
            9010 => 'Invalid setup',
            9020 => 'Unknown command',
            9030 => 'Command not implemented',
            9999 => 'Unknown error',
        ];
        return $errors;
    }

    public static function getErrorMsg($code = 9999)
    {
        $errors = static::getError();

        if (!isset($errors[$code])) {
            throw new RuntimeException('Error ' . $code . ' not defined.');
        }

        return $errors[$code];
    }

    public function sendError($code = 9999, $msgName = '')
    {
        $msg = static::getErrorMsg($code);

        if ($code >= 2000 && $code <= 3999) {
            // SSL
            $this->logColor('debug', 'send ERROR: ' . $code . ', ' . $msg, 'green');
        } elseif ($code >= 5000 && $code <= 5999) {
            // Bridge
            $this->logColor('debug', 'send ERROR: ' . $code . ', ' . $msg, 'yellow');
        } else {
            $this->log('debug', 'send ERROR: ' . $code . ', ' . $msg);
        }

        $data = [
            'code' => $code,
            'msg' => $msg,
            'name' => $msgName,
        ];
        return $this->dataSend($this->msgCreate('error', $data));
    }

    public function sendQuit()
    {
        return $this->dataSend($this->msgCreate('quit'));
    }

    private function sslMsgCreatePublicEncrypt($name, $data)
    {
        $data = json_encode($data);
        $dataEnc = $this->sslPublicEncrypt($data);

        if ($dataEnc) {
            $json = [
                'name' => $name,
                'data' => $dataEnc,
            ];

            return json_encode($json);
        }

        return null;
    }

    private function sslMsgDataPrivateDecrypt($dataEnc)
    {
        $data = $this->sslPrivateDecrypt($dataEnc);
        if ($data) {
            $data = json_decode($data, true);

            return $data;
        }

        return null;
    }

    private function sslMsgCreatePasswordEncrypt($name, $data,
                                                 $sslPasswordLocalCurrent = null, $sslPasswordPeerCurrent = null)
    {
        $this->sslMsgCount++;

        $data = json_encode($data);
        $dataEnc = $this->sslPasswordEncrypt($data, $sslPasswordLocalCurrent, $sslPasswordPeerCurrent);

        if ($dataEnc) {
            $json = [
                'name' => $name,
                'data' => $dataEnc,
            ];
            return json_encode($json);
        }

        $this->logColor('debug', 'SSL msg create: failed (' . $name . ')', 'green');

        return null;
    }

    private function sslMsgDataPasswordDecrypt($dataEnc,
                                               $sslPasswordLocalCurrent = null, $sslPasswordPeerCurrent = null)
    {
        $data = $this->sslPasswordDecrypt($dataEnc, $sslPasswordLocalCurrent, $sslPasswordPeerCurrent);
        if ($data) {
            $data = json_decode($data, true);
            return $data;
        }

        return null;
    }

    private function sslPublicEncrypt($data)
    {

        if (openssl_sign($data, $sign, $this->getSsl(), OPENSSL_ALGO_SHA1)) {
            $sign = base64_encode($sign);

            if (openssl_public_encrypt($data, $cryped, $this->getNode()->getSslKeyPub())) {
                $data = base64_encode($cryped);
                $jsonStr = json_encode(['data' => $data, 'sign' => $sign]);
                $gzdata = gzencode($jsonStr, 9);
                $rv = base64_encode($gzdata);

                return $rv;
            } else {
                $this->log('error', 'sslPublicEncrypt: SSL openssl_public_encrypt failed');
                $this->log('error', 'sslPublicEncrypt data: /' . $data . '/');
                $this->log('error', 'sslPublicEncrypt sign: /' . $sign . '/');
                $this->log('error', 'sslPublicEncrypt key pub: /' . $this->getNode()->getSslKeyPub() . '/');
                while ($openSslErrorStr = openssl_error_string()) {
                    $this->log('error', 'SSL: ' . $openSslErrorStr);
                }
            }
        } else {
            $this->log('error', 'sslPublicEncrypt: SSL openssl_sign failed');
            while ($openSslErrorStr = openssl_error_string()) {
                $this->log('error', 'SSL: ' . $openSslErrorStr);
            }
        }

        return null;
    }

    private function sslPrivateDecrypt($data)
    {
        $data = base64_decode($data);
        $data = gzdecode($data);
        $json = json_decode($data, true);

        if (isset($json['data']) && isset($json['sign'])) {
            $data = base64_decode($json['data']);
            $sign = base64_decode($json['sign']);

            if (openssl_private_decrypt($data, $decrypted, $this->getSsl())) {
                if (openssl_verify($decrypted, $sign, $this->getNode()->getSslKeyPub(), OPENSSL_ALGO_SHA1)) {
                    $rv = $decrypted;

                    return $rv;
                } else {
                    $this->log('error', 'sslPrivateDecrypt: SSL openssl_verify failed');
                    $this->log('error', 'sslPrivateDecrypt data: /' . $data . '/');
                    $this->log('error', 'sslPrivateDecrypt sign: /' . $sign . '/');
                    $this->log('error', 'sslPrivateDecrypt key pub: /' . $this->getNode()->getSslKeyPub() . '/');
                    while ($openSslErrorStr = openssl_error_string()) {
                        $this->log('error', 'SSL: ' . $openSslErrorStr);
                    }
                }
            } else {
                $this->log('error', 'sslPrivateDecrypt: SSL openssl_sign failed');
                while ($openSslErrorStr = openssl_error_string()) {
                    $this->log('error', 'SSL: ' . $openSslErrorStr);
                }
            }
        } else {
            $this->log('error', 'sslPrivateDecrypt failed: data field and sign field not set');
        }

        return null;
    }

    private function sslPasswordEncrypt($data, $sslPasswordLocalCurrent = null, $sslPasswordPeerCurrent = null)
    {
        #$this->logColor('debug', 'SSL password encrypt A', 'green');

        if ($sslPasswordLocalCurrent === null) {
            #$this->logColor('debug', 'SSL password encrypt: no local password set', 'green');
            $sslPasswordLocalCurrent = $this->sslPasswordLocalCurrent;
        }
        if ($sslPasswordPeerCurrent === null) {
            #$this->logColor('debug', 'SSL password encrypt: no peer password set', 'green');
            $sslPasswordPeerCurrent = $this->sslPasswordPeerCurrent;
        }

        #$this->logColor('debug', 'SSL password encrypt', 'green');

        if ($sslPasswordLocalCurrent && $sslPasswordPeerCurrent) {
            $password = $sslPasswordLocalCurrent . '_' . $sslPasswordPeerCurrent;
            #$this->logColor('debug', 'SSL password encrypt pwd: '.$password, 'green');

            if (openssl_sign($data, $sign, $this->getSsl(), OPENSSL_ALGO_SHA1)) {
                $sign = base64_encode($sign);
                $data = base64_encode($data);

                $jsonStr = json_encode(['data' => $data, 'sign' => $sign]);
                $data = gzencode($jsonStr, 9);

                $iv = substr(hash('sha512', mt_rand(0, 999999), true), 0, 16);
                $data = openssl_encrypt($data, 'AES-256-CBC', $password, 0, $iv);
                if ($data !== false) {
                    $iv = base64_encode($iv);

                    $data = gzencode(json_encode(['data' => $data, 'iv' => $iv]), 9);
                    $rv = base64_encode($data);

                    return $rv;
                }
            }
        }

        $this->logColor('debug', 'SSL password encrypt: failed', 'green');

        return null;
    }

    private function sslPasswordDecrypt($data, $sslPasswordLocalCurrent = null, $sslPasswordPeerCurrent = null)
    {
        #$this->logColor('debug', 'SSL password decrypt', 'green');

        if ($sslPasswordLocalCurrent === null) {
            #$this->logColor('debug', 'SSL password decrypt: no local password set', 'green');
            $sslPasswordLocalCurrent = $this->sslPasswordLocalCurrent;
        }
        if ($sslPasswordPeerCurrent === null) {
            #$this->logColor('debug', 'SSL password decrypt: no peer password set', 'green');
            $sslPasswordPeerCurrent = $this->sslPasswordPeerCurrent;
        }

        if ($sslPasswordLocalCurrent && $sslPasswordPeerCurrent) {
            $password = $sslPasswordPeerCurrent . '_' . $sslPasswordLocalCurrent;
            #$this->logColor('debug', 'SSL password decrypt pwd: '.$password, 'green');

            $data = base64_decode($data);
            $json = json_decode(gzdecode($data), true);
            if (isset($json['data']) && isset($json['iv'])) {
                $data = $json['data'];
                $iv = base64_decode($json['iv']);

                $data = openssl_decrypt($data, 'AES-256-CBC', $password, 0, $iv);
                if ($data !== false) {
                    $json = json_decode(gzdecode($data), true);
                    if (isset($json['data']) && isset($json['sign'])) {
                        $data = base64_decode($json['data']);
                        $sign = base64_decode($json['sign']);

                        if (openssl_verify($data, $sign, $this->getNode()->getSslKeyPub(), OPENSSL_ALGO_SHA1)) {
                            return $data;
                        } else {
                            $this->log('warning', 'sslPasswordDecrypt: openssl_verify failed');
                        }
                    } else {
                        $this->log('warning', 'sslPasswordDecrypt: data or sign not set');
                    }
                } else {
                    $this->log('warning', 'sslPasswordDecrypt: openssl_decrypt failed');
                    while ($openSslErrorStr = openssl_error_string()) {
                        $this->log('error', 'SSL: ' . $openSslErrorStr);
                    }
                }
            } else {
                $this->log('warning', 'sslPasswordDecrypt: data or iv not set');
            }
        } else {
            $this->log('warning', 'sslPasswordDecrypt: no passwords set');
        }

        return null;
    }

    /**
     * @codeCoverageIgnore
     */
    public function shutdown()
    {
    }

    private function consoleMsgAdd($msgText = '', $showDate = true, $printPs1 = true, $clearLine = false)
    {
        if ($this->getServer()) {
            $this->getServer()->consoleMsgAdd($msgText, $showDate, $printPs1, $clearLine);
        }
    }

    private function consoleTalkRequestAdd($rid, $userNickname)
    {
        if (
            $this->getServer()
            && $this->getServer()->getKernel()
            && $this->getServer()->getKernel()->getIpcConsoleConnection()
        ) {

            $this->getServer()->getKernel()->getIpcConsoleConnection()->execAsync('talkRequestAdd',
                [$this, $rid, $userNickname])
            ;
        }
    }

    private function consoleTalkMsgAdd($rid, $userNickname, $text)
    {
        if (
            $this->getServer()
            && $this->getServer()->getKernel()
            && $this->getServer()->getKernel()->getIpcConsoleConnection()
        ) {

            $this->getServer()->getKernel()->getIpcConsoleConnection()
                ->execAsync('talkMsgAdd', [$rid, $userNickname, $text])
            ;
        }
    }

    private function consoleSetModeChannel($modeChannel)
    {
        if ($this->getServer()) {
            $this->getServer()->consoleSetModeChannel($modeChannel);
        }
    }

    private function consoleSetModeChannelClient($client)
    {
        if ($this->getServer()) {
            $this->getServer()->consoleSetModeChannelClient($client);
        }
    }
}
