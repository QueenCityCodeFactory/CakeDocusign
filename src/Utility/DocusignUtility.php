<?php
namespace Docusign\Utility;

use Cake\Core\Configure;
use Cake\Filesystem\File;
use Cake\Network\Exception\InternalErrorException as Exception;
use Cake\Routing\Router;
use DocuSign\eSign\ApiClient;
use DocuSign\eSign\ApiException;
use DocuSign\eSign\Api\AuthenticationApi;
use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Api\UsersApi;
use DocuSign\eSign\Configuration;
use DocuSign\eSign\Model;
use DocuSign\eSign\ObjectSerializer;

class DocusignUtility
{

    /**
     * Global Config
     * @var Array
     */
    protected $globalConfig;

    /**
     * Account ID
     * @var string
     */
    public $accountId;

    /**
     * The DocuSign API Client
     * @var \DocuSign\eSign\ApiClient
     */
    public $apiClient;

    /**
     * The callback URL for event notifications
     * @var string
     */
    public $callbackUrl;

    /**
     * The Config object
     * @var \DocuSign\eSign\Configuration
     */
    public $config;

    /**
     * Default config options
     * @var array
     */
    protected $defaults;

    /**
     * Envelope API
     * @var \DocuSign\eSign\Api\EnvelopesApi
     */
    protected $envelopeApi;

    /**
     * Options for the envelope object
     * @var \DocuSign\eSign\Api\EnvelopesApi\CreateEnvelopeOptions
     */
    protected $envelopeOptions;

    /**
     * Login Account
     * @var \DocuSign\eSign\Model\LoginAccount
     */
    protected $loginAccount;

    /**
     * Login information
     * @var \DocuSign\eSign\Model\LoginInformation
     */
    protected $loginInfo;

    /**
     * Envelope object
     * @var \DocuSign\eSign\Model\Envelope
     */
    public $envelope;

    /**
     * Construct method
     *
     * @return void
     */
    public function __construct()
    {
        $this->globalConfig = Configure::read('Docusign');
        $this->defaults = Configure::read('Docusign.defaults');
        $configOptions = Configure::read('Docusign.config');
        $this->config = $this->getConfig($configOptions);
        $this->apiClient = $this->setClient($this->config);
        $this->loginInfo = $this->login($this->apiClient);
    }

    /**
     * Set Client
     * @param Configuration $config The config object
     * @return DocuSign\eSign\ApiClient
     */
    public function setClient(Configuration $config)
    {
        $apiClient = new ApiClient($config);

        return $apiClient;
    }

    /**
     * setup config
     * @param array $configOptions the configuration options
     * @return DocuSign\eSign\Configuration
     */
    protected function getConfig(array $configOptions)
    {
        $config = new Configuration();
        $config->setHost($configOptions['host']);
        $config->addDefaultHeader("X-DocuSign-Authentication", "{\"Username\":\"" . $configOptions['username'] . "\",\"Password\":\"" . $configOptions['password'] . "\",\"IntegratorKey\":\"" . $configOptions['integrator_key'] . "\"}");

        return $config;
    }

    /**
     * Login
     * @param ApiClient $apiClient the API client
     * @return \DocuSign\eSign\Model\LoginInformation
     * @throws \Cake\Network\Exception\InternalErrorException upon failure
     */
    protected function login(ApiClient $apiClient)
    {
        $authenticationApi = new AuthenticationApi($apiClient);
        $options = new AuthenticationApi\LoginOptions();
        try {
            $loginInformation = $authenticationApi->login($options);
            if (isset($loginInformation) && count($loginInformation) > 0) {
                $loginAccounts = $loginInformation->getLoginAccounts();
                $globalConfig = $this->globalConfig;
                $accountId = $globalConfig['config']['accountId'];
                $_loginAccount = null;
                foreach ($loginAccounts as $_account) {
                    if ($_account->getAccountId() === $accountId) {
                        $_loginAccount = $_account;
                    }
                }

                if (empty($_loginAccount)) {
                    $_loginAccount = $loginAccounts[0];
                }

                $this->loginAccount = $_loginAccount;
                $host = $this->loginAccount->getBaseURL();
                $host = explode('/v2', $host);
                $host = $host[0];
                $this->config->setHost($host);
                $this->apiClient = new ApiClient($this->config);
                $this->accountId = $this->loginAccount->getAccountId();

                return $loginInformation;
            }
        } catch (ApiException $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Setup the envelope API
     *
     * @return \DocuSign\eSign\Api\EnvelopesApi
     */
    protected function setEnvelopeApi()
    {
        return new EnvelopesApi($this->apiClient);
    }

    /**
     * Instantiates a new envelope object
     *
     * @param  string $emailSubject the email subject
     * @return \DocuSign\eSign\Model\Envelope
     */
    public function createEnvelope($emailSubject = null)
    {
        $this->envelopeApi = $this->setEnvelopeApi();
        $this->envelopeOptions = $this->setEnvelopeOptions();
        if (is_null($emailSubject)) {
            $emailSubject = $this->defaults['email']['subject'];
        }
        $envelope = new Model\EnvelopeDefinition();
        $envelope->setEmailSubject($emailSubject);
        $this->envelope = $envelope;

        return $envelope;
    }

    /**
     * Set envelope object options
     *
     * @return \DocuSign\eSign\Api\EnvelopesApi\CreateEnvelopeOptions
     */
    protected function setEnvelopeOptions()
    {
        $options = new EnvelopesApi\CreateEnvelopeOptions();
        $options->setCdseMode(null);
        $options->setMergeRolesOnDraft(null);

        return $options;
    }

    /**
     * Set envelope recipients
     *
     * @param array $recipients The envelope recipients
     * @return void
     */
    public function setRecipients(array $recipients)
    {
        $recipientModel = new Model\Recipients();
        $signers = [];
        foreach ($recipients['signers'] as $signer) {
            $signers[] = new Model\Signer($signer);
        }

        $inPersonSigners = [];
        foreach ($recipients['in_person_signers'] as $signer) {
            $inPersonSigners[] = new Model\InPersonSigner($signer);
        }

        $recipientModel->setSigners($signers);
        $recipientModel->setInPersonSigners($inPersonSigners);
        $this->envelope->setRecipients($recipientModel);
    }

    /**
     * send method
     *
     * @param bool $eventNotification Whether or not to setup event notifications
     * @return \DocuSign\eSign\Model\EnvelopeSummary - summary of envelope
     * @throws \Cake\Network\Exception\InternalErrorException upon failure
     */
    public function sendEnvelope($eventNotification = true)
    {
        if ($eventNotification === true) {
            $this->addEventNotification();
        }
        $recipients = $this->envelope->getRecipients();
        $documents = $this->envelope->getDocuments();
        if (is_null($recipients)) {
            throw new Exception('You cannot send an envelope without any recipients.');
        } elseif (is_null($documents)) {
            throw new Exception('You cannot send an envelope without any documents.');
        }
        $this->envelope->setStatus('sent');
        try {
            return $this->envelopeApi->createEnvelope($this->accountId, $this->envelope, $this->envelopeOptions);
        } catch (ApiException $e) {
            $error = json_decode(json_encode($e->getResponseBody()), true);
            $error = implode(" - ", $error);
            throw new Exception($error);
        }
    }

    /**
     * Get Users method
     *
     * @param array  $queryParams  parameters to be place in query URL
     * @param array  $postData     parameters to be placed in POST body
     * @param array  $headerParams parameters to be place in request header
     * @param string $responseType expected response type of the endpoint
     * @return mixed
     */
    public function getUsers($queryParams = null, $postData = null, $headerParams = null, $responseType = null)
    {
        $request = $this->apiClient->callApi('/v2/accounts/' . $this->accountId . '/users', 'GET', $queryParams, $postData, $headerParams, $responseType);

        return $request[0];
    }

    /**
     * Create User method
     *
     * @param array $postData The post data
     * @return string The User ID
     */
    public function createUser(array $postData)
    {
        $config = $this->getConfig(Configure::read('Docusign.config'));
        $config->addDefaultHeader('X-DocuSign-SDK', 'PHP');
        $config->addDefaultHeader('Content-Type', 'application/json');
        $apiClient = new ApiClient($config);

        $response = $apiClient->callApi('/v2/accounts/' . $this->accountId . '/users', 'POST', null, $postData, null, null);
        $userId = $response[0]->newUsers[0]->userId;

        return $userId;
    }

    /**
     * Delete User method
     *
     * @param string|null $id The User ID
     * @return mixed
     */
    public function deleteUser($id = null)
    {
        if (is_null($id)) {
            return false;
        }
        $postData = [
            'users' => [
                [
                    'userId' => $id
                ]
            ]
        ];

        $config = $this->getConfig(Configure::read('Docusign.config'));
        $config->addDefaultHeader('X-DocuSign-SDK', 'PHP');
        $config->addDefaultHeader('Content-Type', 'application/json');
        $apiClient = new ApiClient($config);

        $response = $apiClient->callApi('/v2/accounts/' . $this->accountId . '/users', 'DELETE', null, $postData, null, null);

        return $response;
    }

    /**
     * Set Signing Tabs method
     *
     * @param array $recipients The envelope recipients
     * @param int $documentId The document's id
     * @param array $anchorConfig Configuration options for anchor tabs
     * @return array
     */
    public static function setSigningTabs(array $recipients, $documentId = 1, $anchorConfig = [])
    {
        if (empty($anchorConfig)) {
            $anchorConfig = [
                'anchor_x_offset' => '0',
                'anchor_y_offset' => '-27',
                'anchor_units' => 'pixels'
            ];
        }
        foreach ($recipients as $type => &$collection) {
            foreach ($collection as $key => &$signer) {
                $signHereTab = new Model\SignHere([
                    'document_id' => $documentId,
                    'anchor_string' => $signer['role'] . ' Signature',
                    'anchor_x_offset' => $anchorConfig['anchor_x_offset'],
                    'anchor_y_offset' => $anchorConfig['anchor_y_offset'],
                    'anchor_units' => $anchorConfig['anchor_units']
                ]);

                $dateSignedTab = new Model\DateSigned([
                    'document_id' => $documentId,
                    'anchor_string' => $signer['role'] . ' Date',
                    'anchor_x_offset' => $anchorConfig['anchor_x_offset'],
                    'anchor_y_offset' => $anchorConfig['anchor_y_offset'],
                    'anchor_units' => $anchorConfig['anchor_units']
                ]);

                $signer['tabs'] = [
                    'signHereTabs' => [
                        $signHereTab
                    ],
                    'dateSignedTabs' => [
                        $dateSignedTab
                    ]
                ];
            }
        }

        return $recipients;
    }

    /**
     * Add Event Notification method
     * @return void
     */
    protected function addEventNotification()
    {
        $envelopeEvents = [
            new Model\EnvelopeEvent([
                'envelope_event_status_code' => 'Completed',
                'include_documents' => true
            ]),
            new Model\EnvelopeEvent([
                'envelope_event_status_code' => 'Declined',
                'include_documents' => false
            ]),
            new Model\EnvelopeEvent([
                'envelope_event_status_code' => 'Voided',
                'include_documents' => false
            ])
        ];

        $eventNotification = new Model\EventNotification([
            'url' => $this->callbackUrl,
            'logging_enabled' => true,
            'require_acknowledgment' => true,
            'envelope_events' => $envelopeEvents,
            'use_soap_interface' => false,
            'sign_message_with_x509_cert' => true,
            'include_documents' => true,
            'include_envelope_void_reason' => true,
            'include_time_zone' => true,
            'include_sender_account_as_custom_field' => false,
            'include_document_fields' => false,
            'include_certificate_of_completion' => true
        ]);

        $this->envelope->setEventNotification($eventNotification);
    }

    /**
     * Writes a file to the configured filepath
     *
     * @param  string $pdfBase64 Base64 encoded pdf file contents
     * @param  string $fileName  Name of the file - defaults to `time()`
     * @return array Associative array of file data
     */
    public static function saveFile($pdfBase64, $fileName = null)
    {
        $globalConfig = Configure::read('Docusign');
        $filePath = $globalConfig['paths']['file'];
        if (is_null($fileName)) {
            $fileName = time() . '.pdf';
        }
        $file = new File("{$filePath}/{$fileName}", true, 0777);
        $file->open('w');
        $file->write(base64_decode($pdfBase64), true);
        $file->close();
        $info = $file->info();

        return [
            'name' => $fileName,
            'type' => $info['mime'],
            'tmp_name' => $file->pwd(),
            'size' => $file->size(),
            'error' => 0
        ];
    }
}
