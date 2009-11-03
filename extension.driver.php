<?php
	
	class Extension_SecurePay extends Extension {
		
		public function about() {
			return array(
				'name'			=> 'SecurePay',
				'version'		=> '1.0',
				'release-date'	=> '2009-11-03',
				'author'		=> array(
					'name'			=> '',
					'website'		=> '',
					'email'			=> ''
				),
				'description' => 'Manage and track payments using SecurePay.'
			);
		}
		
		/*//////////////////////////////////////////////////////////////
		//	Setup
		/////////////////////////////////////////////////////////////*/
		
		private $defaults = array(
			'messageID' => '',
			'messageTimestamp' => '',
			'timeoutValue' => '60',
			'apiVersion' => 'xml-4.2',
			'merchantID' => '',
			'password' => '',
			'RequestType' => 'Payment',
			'txnType' => '0',
			'txnSource' => '23',
			'amount' => '',
			'purchaseOrderNo' => '',
			'cardNumber' => '',
			'expiryDate' => ''
		);
		
		private $required_fields = array(
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
		
		private $approved_codes = array(
			'00', //Approved
			'08', //Honour with ID
			'10', //Partial Amount Approved
			'11', //Approved VIP
			'16', //Approved, Update Track 3
			'77'  //Approved (ANZ only)
		);
		
		public function install() {
			$this->_Parent->Configuration->set('merchant-id', '', 'securepay');
			$this->_Parent->Configuration->set('merchant-password', '', 'securepay');
			$this->_Parent->Configuration->set('production-gateway-uri', 'https://www.securepay.com.au/xmlapi/payment', 'securepay');
			$this->_Parent->Configuration->set('development-gateway-uri', 'https://www.securepay.com.au/test/payment', 'securepay');
			$this->_Parent->Configuration->set('development-mode', false, 'securepay');
			$this->_Parent->saveConfig();
		}
		
		public function uninstall() {
			$this->_Parent->Configuration->remove('securepay');
			$this->_Parent->saveConfig();
		}
		
		/*//////////////////////////////////////////////////////////////
		//	Utilities
		/////////////////////////////////////////////////////////////*/
		
		public function configuration() {
			return $this->_Parent->Configuration;
		}
		
		public function isTesting() {
			return (boolean)$this->configuration()->get('development-mode', 'securepay');
		}
		
		public function getgatewayURI() {
			$mode = 'production';
			if ($this->isTesting()) $mode = 'development';
			return (string)$this->configuration()->get("{$mode}-gateway-uri", 'securepay');
		}
		
		public function getCustomerId() {
			return (string)$this->configuration()->get("merchant-id", 'securepay');
		}

		public function getCustomerPassword() {
			return (string)$this->configuration()->get("merchant-password", 'securepay');
		}
	
		public function getFields($section_id) {
			$frontend = Frontend::instance();
			$sectionManager = new SectionManager($frontend);
			$section = $sectionManager->fetch($section_id);
			
			if (empty($section)) return null;
			
			return $section->fetchFields();
		}
	
		public function fetchEntry($entries) {
			$frontend = Frontend::instance();
			$entryManager = new EntryManager($frontend);
			$entries = $entryManager->fetch($entries);

			$finalentries  = array();
			foreach ($entries as $order => $entry) {
				$section_id = $entryManager->fetchEntrySectionID($entry->_fields['id']);

				$fields = $this->getFields($section_id);
				$entry_data = array('id'=>$entry->_fields['id']);
				
				foreach ($fields as $field) {
					$field_handle = Lang::createHandle($field->get('label'));
					$entry_data[$field_handle] = $entry->getData($field->get('id'));
				}
				//$finalentries[$entry->_fields['id']] = $entry_data;
				return $entry_data;
			}
			return $finalentries;
		}
		
		public function fetchEntries($entries) {
			if (!is_array($entries)) $entries = array($entries);
			$returnarray = array();
			foreach ($entries as $entry) {
				$returnarray[$entry] = $this->fetchEntry($entry);
			}
			return $returnarray;
		}

		/*//////////////////////////////////////////////////////////////
		//	Process Payments
		/////////////////////////////////////////////////////////////*/
		
		public function process_transaction($values) {
			//Merge Defaults and passed values
			$request_array = array_merge($this->defaults,$values);
			
			//Authentication
			$request_array['merchantID'] = $this->getCustomerId();
			$request_array['password'] = $this->getCustomerPassword();
			
			//Message Info
			$request_array['messageID'] = md5(time());
			$request_array['messageTimestamp'] = time();
			
			//Check for missing fields
			$valid_data = true;
			$missing_fields = array();
			foreach ($this->required_fields as $field_name) {
				if ($request_array[$field_name] == '' OR !array_key_exists($field_name,$request_array)) {
					$missing_fields[] = $field_name;
					$valid_data = false;
				}
			}
			
			if ($valid_data) {

				//Generate the XML
				$SecurePayMessage = new XMLElement('SecurePayMessage');

				$MessageInfo = new XMLElement('MessageInfo');
				$MerchantInfo = new XMLElement('MerchantInfo');
				$RequestType = new XMLElement('RequestType',$request_array['RequestType']);
				$Payment = new XMLElement('Payment');
				$TxnList = new XMLElement('TxnList');
				$Txn = new XMLElement('Txn');
				$CreditCardInfo = new XMLElement('CreditCardInfo');
				
				$MessageInfo->appendChild(new XMLElement('messageID',$request_array['messageID']));
				$MessageInfo->appendChild(new XMLElement('messageTimestamp',$request_array['messageTimestamp']));
				$MessageInfo->appendChild(new XMLElement('timeoutValue',$request_array['timeoutValue']));
				$MessageInfo->appendChild(new XMLElement('apiVersion',$request_array['apiVersion']));
				
				$MerchantInfo->appendChild(new XMLElement('merchantID',$request_array['merchantID']));
				$MerchantInfo->appendChild(new XMLElement('password',$request_array['password']));
				
				$Txn->setAttribute('ID', '1');
				$Txn->appendChild(new XMLElement('txnType',$request_array['txnType']));
				$Txn->appendChild(new XMLElement('txnSource',$request_array['txnSource']));
				$Txn->appendChild(new XMLElement('amount',$request_array['amount']));
				$Txn->appendChild(new XMLElement('purchaseOrderNo',$request_array['purchaseOrderNo']));
				
				$CreditCardInfo->appendChild(new XMLElement('cardNumber',$request_array['cardNumber']));
				$CreditCardInfo->appendChild(new XMLElement('expiryDate',$request_array['expiryDate']));
				
				$Txn->appendChild($CreditCardInfo);
				
				$TxnList->setAttribute('count','1');
				$TxnList->appendChild($Txn);
				
				$Payment->appendChild($TxnList);

				$SecurePayMessage->appendChild($MessageInfo);
				$SecurePayMessage->appendChild($MerchantInfo);
				$SecurePayMessage->appendChild($RequestType);
				$SecurePayMessage->appendChild($Payment);
				
				//Curl
				require_once('library/curl.php');
				$curl = new Curl;
				$curl_result = $curl->post(
					$this->getgatewayURI(),
					$SecurePayMessage->generate()
				);
				
				// Create a document for the result and load the resul
				$securepay_result = new DOMDocument('1.0', 'utf-8');
				$securepay_result->formatOutput = true;
				$securepay_result->loadXML($curl_result->body);
				$securepay_result_xpath = new DOMXPath($securepay_result);

				// Generate status result:
				$securepay_transaction_id   = $securepay_result_xpath->evaluate('string(/SecurePayMessage/Payment/TxnList/Txn/txnID)');
				$bank_authorisation_id = '';

				if (strtolower($securepay_result_xpath->evaluate('string(/SecurePayMessage/Payment/TxnList/Txn/approved)')) == 'yes') {
					$securepay_approved = true;
				} else {
					$securepay_approved = false;
				}

				$securepay_error_code = $securepay_result_xpath->evaluate('string(/SecurePayMessage/Payment/TxnList/Txn/responseCode)');;
				$securepay_error_message = $securepay_result_xpath->evaluate('string(/SecurePayMessage/Payment/TxnList/Txn/responseText)');
				
				return(array(
					'pgi-transaction-id'=> $securepay_transaction_id,
					'bank-authorisation-id'=> $bank_authorisation_id,
					'status'		=> ($securepay_approved ? 'Approved' : 'Declined'),
					'response-code'	=> $securepay_error_code,
					'response-message'	=> $securepay_error_message
				));
			} else {
				return(array(
					'status'		=> 'Error',
					'response-code'	=> $securepay_error_code,
					'response-message'	=> 'Missing Fields: '.implode(', ', $missing_fields),
					'missing-fields'=>	$missing_fields
				));
			}
		}
	}

?>
