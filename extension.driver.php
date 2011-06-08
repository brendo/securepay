<?php

	class Extension_SecurePay extends Extension {

		const GATEWAY_ERROR = 100;
		const DATA_ERROR = 200;

		public function about() {
			return array(
				'name'			=> 'SecurePay',
				'version'		=> '1.0',
				'release-date'	=> '2009-11-03',
				'author'		=> array(
					array(
						'name' => 'Brendan Abbott',
						'email' => 'brendan@bloodbone.ws'
					),
					array(
						'name' => 'Henry Singleton'
					)
				),
				'description' => 'Manage and track payments using SecurePay.'
			);
		}

		private static $defaults = array(
			'messageID' => '',
			'messageTimestamp' => '',
			'timeoutValue' => '60',
			'apiVersion' => 'xml-4.2',
			'merchantID' => '',
			'password' => '',
			'RequestType' => 'Payment',
			'txnType' => '0',
			'txnSource' => '0',
			'amount' => '',
			'purchaseOrderNo' => '',
			'cardNumber' => '',
			'expiryDate' => '',
			'cvv' => ''
		);

		private static $required_fields = array(
			'messageID',
			'messageTimestamp',
			'timeoutValue',
			'apiVersion',
			'merchantID',
			'password',
			'RequestType',
			'txnType',
			'txnSource',
			'amount',
			'purchaseOrderNo',
			'cardNumber',
			'expiryDate',
		);

		private static $approved_codes = array(
			'00', //Approved
			'08', //Honour with ID
			'10', //Partial Amount Approved
			'11', //Approved VIP
			'16', //Approved, Update Track 3
			'77'  //Approved (ANZ only)
		);

	/*-------------------------------------------------------------------------
		Definition:
	-------------------------------------------------------------------------*/

		public function install() {
			Symphony::Configuration()->set('merchant-id', '', 'securepay');
			Symphony::Configuration()->set('merchant-password', '', 'securepay');
			Symphony::Configuration()->set('production-gateway-uri', 'https://www.securepay.com.au/xmlapi/payment', 'securepay');
			Symphony::Configuration()->set('development-gateway-uri', 'https://www.securepay.com.au/test/payment', 'securepay');
			Symphony::Configuration()->set('gateway-mode', 'development', 'securepay');
			Administration::instance()->saveConfig();
		}

		public function uninstall() {
			Symphony::Configuration()->remove('securepay');
			Administration::instance()->saveConfig();
		}

		public function getSubscribedDelegates() {
			return array(
				array(
					'page'		=> '/system/preferences/',
					'delegate'	=> 'AddCustomPreferenceFieldsets',
					'callback'	=> 'appendPreferences'
				),
				array(
					'page'		=> '/system/preferences/',
					'delegate'	=> 'Save',
					'callback'	=> 'savePreferences'
				)
			);
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		public static function isTesting() {
			return Symphony::Configuration()->get('gateway-mode', 'securepay') == 'development';
		}

		public static function getGatewayURI() {
			$mode = self::isTesting() ? 'development' : 'production';
			return (string)Symphony::Configuration()->get("{$mode}-gateway-uri", 'securepay');
		}

		public static function getMerchantId() {
			return (string)Symphony::Configuration()->get("merchant-id", 'securepay');
		}

		public static function getMerchantPassword() {
			return (string)Symphony::Configuration()->get("merchant-password", 'securepay');
		}

		public static function getFields($section_id) {
			$sectionManager = new SectionManager(Frontend::instance());
			$section = $sectionManager->fetch($section_id);

			if (!($section instanceof Section)) return array();

			return $section->fetchFields();
		}

		public static function fetchEntries($entries) {
			$result = array();

			if (!is_array($entries)) $entries = array($entries);

			foreach ($entries as $entry) {
				$result[$entry] = self::fetchEntry($entry);
			}

			return $result;
		}

		public static function fetchEntry($entries) {
			$entryManager = new EntryManager(Frontend::instance());
			$entries = $entryManager->fetch($entries);

			foreach ($entries as $order => $entry) {
				$section_id = $entry->get('section_id');

				$fields = self::getFields($section_id);
				$entry_data = array(
					'id'=> $entry->get('id')
				);

				foreach ($fields as $field) {
					$entry_data[$field->get('element_name')] = $entry->getData($field->get('id'));
				}

				return $entry_data;
			}
		}

	/*-------------------------------------------------------------------------
		Delegate Callbacks:
	-------------------------------------------------------------------------*/

		/**
		 * Allows a user to enter their eWay details to be saved into the Configuration
		 *
		 * @uses AddCustomPreferenceFieldsets
		 */
		public static function appendPreferences($context) {
			// If the Payment Gateway Interface extension is installed, don't
			// double display the preference, unless this function is called from
			// the `pgi-loader` context.
			if(
				Symphony::ExtensionManager()->fetchStatus('pgi_loader') == EXTENSION_ENABLED
				xor isset($context['pgi-loader'])
			) return;

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('SecurePay')));

			$div = new XMLElement('div', null, array('class' => 'group'));

			// Merchant ID
			$label = new XMLElement('label', __('Merchant ID'));
			$label->appendChild(
				Widget::Input('settings[securepay][merchant-id]', self::getMerchantId())
			);
			$div->appendChild($label);

			// Merchant ID
			$label = new XMLElement('label', __('Merchant Password'));
			$label->appendChild(
				Widget::Input('settings[securepay][merchant-password]', self::getMerchantPassword())
			);
			$div->appendChild($label);
			$fieldset->appendChild($div);

			$div = new XMLElement('div');

			// Build the Gateway Mode
			$label = new XMLElement('label', __('Gateway Mode'));
			$options = array(
				array('development', self::isTesting(), __('Development')),
				array('production', !self::isTesting(), __('Production'))
			);

			$label->appendChild(Widget::Select('settings[securepay][gateway-mode]', $options));
			$div->appendChild($label);

			$fieldset->appendChild($div);
			$context['wrapper']->appendChild($fieldset);
		}

		/**
		 * Saves the Customer ID and the gateway mode to the configuration
		 *
		 * @uses savePreferences
		 */
		public function savePreferences(array &$context){
			$settings = $context['settings'];

			// Active Section
			Symphony::Configuration()->set('merchant-id', $settings['securepay']['merchant-id'], 'securepay');
			Symphony::Configuration()->set('merchant-password', $settings['securepay']['merchant-password'], 'securepay');
			Symphony::Configuration()->set('gateway-mode', $settings['securepay']['gateway-mode'], 'securepay');

			Administration::instance()->saveConfig();
		}

	/*-------------------------------------------------------------------------
		Process Transaction:
	-------------------------------------------------------------------------*/

		public static function processTransaction(array $values = array()) {
			// Merge Defaults and passed values
			$request_array = array_merge(Extension_SecurePay::$defaults, $values);

			// Authentication
			$request_array['merchantID'] = self::getMerchantId();
			$request_array['password'] = self::getMerchantPassword();

			// Message Info
			$request_array['messageID'] = md5(time());
			$request_array['messageTimestamp'] = time();

			$request_array = array_intersect_key($request_array, Extension_SecurePay::$defaults);

			// Check for missing fields
			$valid_data = true;
			$missing_fields = array();
			$error = null;
			foreach (Extension_SecurePay::$required_fields as $field_name) {
				if (!array_key_exists($field_name, $request_array) || empty($request_array[$field_name])) {
					$missing_fields[] = $field_name;
					$valid_data = false;
				}
			}

			// The data is invalid, return a `DATA_ERROR`
			if(!$valid_data) {
				return(array(
					'status' => __('Data error'),
					'response-code' => Extension_SecurePay::DATA_ERROR,
					'response-message' => __('Missing Fields: %s', array(implode(', ', $missing_fields))),
					'missing-fields' => $missing_fields
				));
			}

			//Generate the XML
			$SecurePayMessage = new XMLElement('SecurePayMessage');

			$MessageInfo = new XMLElement('MessageInfo');
			$MessageInfo->appendChild(new XMLElement('messageID',$request_array['messageID']));
			$MessageInfo->appendChild(new XMLElement('messageTimestamp',$request_array['messageTimestamp']));
			$MessageInfo->appendChild(new XMLElement('timeoutValue',$request_array['timeoutValue']));
			$MessageInfo->appendChild(new XMLElement('apiVersion',$request_array['apiVersion']));
			$SecurePayMessage->appendChild($MessageInfo);

			$MerchantInfo = new XMLElement('MerchantInfo');
			$MerchantInfo->appendChild(new XMLElement('merchantID',$request_array['merchantID']));
			$MerchantInfo->appendChild(new XMLElement('password',$request_array['password']));
			$SecurePayMessage->appendChild($MerchantInfo);

			$RequestType = new XMLElement('RequestType',$request_array['RequestType']);
			$SecurePayMessage->appendChild($RequestType);

			$Txn = new XMLElement('Txn');
			$Txn->setAttribute('ID', '1');
			$Txn->appendChild(new XMLElement('txnType',$request_array['txnType']));
			$Txn->appendChild(new XMLElement('txnSource',$request_array['txnSource']));
			$Txn->appendChild(new XMLElement('amount',$request_array['amount']));
			$Txn->appendChild(new XMLElement('purchaseOrderNo',$request_array['purchaseOrderNo']));

			$CreditCardInfo = new XMLElement('CreditCardInfo');
			$CreditCardInfo->appendChild(new XMLElement('cardNumber',$request_array['cardNumber']));
			$CreditCardInfo->appendChild(new XMLElement('expiryDate',$request_array['expiryDate']));
			$CreditCardInfo->appendChild(new XMLElement('cvv', $request_array['cvv']));

			$Txn->appendChild($CreditCardInfo);

			$TxnList = new XMLElement('TxnList');
			$TxnList->setAttribute('count','1');
			$TxnList->appendChild($Txn);

			$Payment = new XMLElement('Payment');
			$Payment->appendChild($TxnList);
			$SecurePayMessage->appendChild($Payment);

			// Curl
			require_once(TOOLKIT . '/class.gateway.php');
			$curl = new Gateway;
			$curl->init(self::getGatewayURI());
			$curl->setopt('POST', true);
			$curl->setopt('POSTFIELDS', $SecurePayMessage->asXML());

			$curl_result = $curl->exec();
			$info = $curl->getInfoLast();

			// The Gateway did not connect to eWay successfully
			if(!in_array($info["http_code"], array('200', '201'))) {
				Symphony::$Log->pushToLog($error, E_USER_ERROR, true);

				// Return a `GATEWAY_ERROR`
				return(array(
					'status' => __('Gateway error'),
					'response-code' => Extension_SecurePay::GATEWAY_ERROR,
					'response-message' => __('There was an error connecting to SecurePay.')
				));
			}

			else {
				// Create a document for the result and load the resul
				$securepay_result = new DOMDocument('1.0', 'utf-8');
				$securepay_result->formatOutput = true;
				$securepay_result->loadXML($curl_result);
				$securepay_result_xpath = new DOMXPath($securepay_result);

				// Generate status result:
				$securepay_transaction_id = $securepay_result_xpath->evaluate('string(/SecurePayMessage/Payment/TxnList/Txn/txnID)');

				$securepay_code = $securepay_result_xpath->evaluate('string(/SecurePayMessage/Payment/TxnList/Txn/responseCode)');;
				$securepay_message = $securepay_result_xpath->evaluate('string(/SecurePayMessage/Payment/TxnList/Txn/responseText)');

				return(array(
					'status' => in_array($eway_code, Extension_SecurePay::$approved_codes) ? __('Approved') : __('Declined'),
					'response-code' => $securepay_code,
					'response-message' => $securepay_message,
					'pgi-transaction-id' => $securepay_transaction_id,
				));
			}
		}
	}
