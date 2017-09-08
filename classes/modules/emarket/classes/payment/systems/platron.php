<?php
class platronPayment extends payment {
	public function validate() { return true; }

	public static function getOrderId() {
		return (int) getRequest('shp_orderId');
	}

	public function process($template = null) {
		$this->order->order();
		$cmsController = cmsController::getInstance();
		$strCurrency = strtoupper( mainConfiguration::getInstance()->get('system', 'default-currency') );
		if ($strCurrency == 'RUR')
			$strCurrency = 'RUB';

		$strDescription = "";
		foreach($this->order->getItems() as $objItem){
			$strDescription .= $objItem->getName();
			if($objItem->getAmount() > 1)
				$strDescription .= "*".$objItem->getAmount();
			$strDescription .= "; ";
		}

		$strLanguage = strtolower( $cmsController->getCurrentLang()->getPrefix() );			
		$strProtocol = !empty( $_SERVER['HTTPS'] ) ? 'https://' : 'http://';
		$www = $strProtocol . $cmsController->getCurrentDomain()->getHost();

		$strSuccessUrl = ( @$this->object->success_url ) ? $this->_http( $this->object->success_url ) : $www . '/emarket/purchase/result/successful/' ;
		$strFailUrl = ( @$this->object->failure_url ) ? $this->_http( $this->object->failure_url ) : $www . '/emarket/purchase/result/failed/';
		$strCallbackUrl = $www . '/emarket/gateway/' . $this->order->getId() . '/index.php';
		$strCheckUrl = ( @$this->object->check_url ) ? $this->object->check_url : $strCallbackUrl;
		$strResultUrl = ( @$this->object->result_url ) ? $this->object->result_url : $strCallbackUrl;

		$bDemoMode = ( @$this->object->demo_mode ) ? 1 : 0;
		$nLifeTime = ( @$this->object->lifetime ) ? $this->object->lifetime*60 : 0;

		$arrFields = array(
			'pg_merchant_id'	=> $this->object->merchant_id,
			'pg_order_id'		=> $this->order->id,
			'pg_currency'		=> $strCurrency,
			'pg_amount'			=> number_format($this->order->getActualPrice(), 2, '.', ''),
			'pg_lifetime'		=> $nLifeTime,
			'pg_testing_mode'	=> $bDemoMode,
			'pg_description'	=> $strDescription,
			'pg_user_ip'		=> $_SERVER['REMOTE_ADDR'],
			'pg_language'		=> $strLanguage,
			'pg_check_url'		=> $strCheckUrl,
			'pg_result_url'		=> $strResultUrl,
			'pg_request_method'	=> 'POST',
			'pg_success_url'	=> $strSuccessUrl,
			'pg_failure_url'	=> $strFailUrl,
			'pg_salt'			=> rand(21,43433), // Параметры безопасности сообщения. Необходима генерация pg_salt и подписи сообщения.
		);

		if(!empty($this->object->payment_system) && !$bDemoMode)
			$arrFields['pg_payment_system'] = $this->object->payment_system;

		$user_id = $this->order->getValue('customer_id');
		$userObject = umiObjectsCollection::getInstance()->getObject( $user_id );
		
		$strMaybePhone = $userObject->getValue('phone') ? $userObject->getValue('phone') : $userObject->getValue('delivery_phone');
		$strMaybeEmail = $userObject->getValue('email') ? $userObject->getValue('email') : $userObject->getValue('e-mail');
				
		preg_match_all("/\d/", $strMaybePhone, $array);
		$strPhone = implode('',@$array[0]);
		$arrFields['pg_user_phone'] = $strPhone;
		
		$arrFields['pg_user_email'] = $strMaybeEmail;
		$arrFields['pg_user_contact_email'] = $strMaybeEmail;
		
		$arrFields['pg_sig'] = PG_Signature::make('payment.php', $arrFields, $this->object->secret_key);
		$this->order->setPaymentStatus('initialized');
		
		list($templateString) = def_module::loadTemplates("emarket/payment/platron/".$template, "form_block");
		return def_module::parseTemplate($templateString, $arrFields);
	}

	public function poll() {
		$arrRequest = array();
		if(!empty($_POST)) 
			$arrRequest = $_POST;
		else {
			foreach($_GET as $strName => $strValue)
				if(preg_match('/^pg_/', $strName))
					$arrRequest[$strName] = $strValue;
		}


		$thisScriptName = PG_Signature::getOurScriptName();
		if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, $this->object->secret_key))
			die("Wrong signature");
		
		$buffer = outputBuffer::current();
		$buffer->clear();
		$buffer->contentType("text/xml");
			
		$arrResponse = array();
		$strOrderStatus = $this->order->getCodeByStatus($this->order->getPaymentStatus());
		$strPaymentStatus = $this->order->getCodeByStatus($this->order->getOrderStatus());

		if(!isset($arrRequest['pg_result'])){
			$bCheckResult = 1;
			if(!isset($this->order) || !in_array($strPaymentStatus, array('payment','waiting','accepted')) || !in_array($strOrderStatus, array('initialized','accepted'))){
				$bCheckResult = 0;
				$error_desc = "Товар не доступен. Либо заказа нет, либо его статус ".$strOrderStatus.', а статус оплаты '.$strPaymentStatus;
			}
			elseif(number_format($this->order->getActualPrice(), 2, '.', '') != number_format($arrRequest['pg_amount'], 2, '.', '')){
				$bCheckResult = 0;
				$error_desc = "Неверная сумма";
			}
			
			$arrResponse['pg_salt']              = $arrRequest['pg_salt']; // в ответе необходимо указывать тот же pg_salt, что и в запросе
			$arrResponse['pg_status']            = $bCheckResult ? 'ok' : 'error';
			$arrResponse['pg_error_description'] = $bCheckResult ?  ""  : $error_desc;
			$arrResponse['pg_sig']				 = PG_Signature::make($thisScriptName, $arrResponse, $this->object->secret_key);
			
			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('pg_salt', $arrResponse['pg_salt']);
			$objResponse->addChild('pg_status', $arrResponse['pg_status']);
			$objResponse->addChild('pg_error_description', $arrResponse['pg_error_description']);
			$objResponse->addChild('pg_sig', $arrResponse['pg_sig']);
			
		}
		else{
			if(!isset($this->order) || !in_array($strPaymentStatus, array('payment','waiting','accepted')) || !in_array($strOrderStatus, array('initialized','accepted'))){
				$strResponseDescription = "Товар не доступен. Либо заказа нет, либо его статус ".$strOrderStatus.', а статус оплаты '.$strPaymentStatus;
				if($arrRequest['pg_can_reject'] == 1)
					$strResponseStatus = 'rejected';
				else
					$strResponseStatus = 'error';
			}
			elseif(number_format($this->order->getActualPrice(), 2, '.', '') != number_format($arrRequest['pg_amount'], 2, '.', '')){
				$strResponseDescription = "Неверная сумма";
				if($arrRequest['pg_can_reject'] == 1)
					$strResponseStatus = 'rejected';
				else
					$strResponseStatus = 'error';
			}
			else {
				$strResponseStatus = 'ok';
				$strResponseDescription = "Оплата принята";
				if ($arrRequest['pg_result'] == 1) {
					$this->order->setPaymentStatus("accepted");
//					$this->order->setOrderStatus("accepted");
					$this->order->payment_document_num = $arrRequest['pg_payment_id'];
				}
				else{
					$this->order->setPaymentStatus("rejected");
//					$this->order->setOrderStatus("rejected");
				}
			}
			
			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('pg_salt', $arrRequest['pg_salt']); // в ответе необходимо указывать тот же pg_salt, что и в запросе
			$objResponse->addChild('pg_status', $strResponseStatus);
			$objResponse->addChild('pg_description', $strResponseDescription);
			$objResponse->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $objResponse, $this->object->secret_key));
		}
		
		$buffer->push($objResponse->asXML());
		$buffer->end();
	}
};
	

class PG_Signature {

	/**
	 * Get script name from URL (for use as parameter in self::make, self::check, etc.)
	 *
	 * @param string $url
	 * @return string
	 */
	public static function getScriptNameFromUrl ( $url )
	{
		$path = parse_url($url, PHP_URL_PATH);
		$len  = strlen($path);
		if ( $len == 0  ||  '/' == $path{$len-1} ) {
			return "";
		}
		return basename($path);
	}
	
	/**
	 * Get name of currently executed script (need to check signature of incoming message using self::check)
	 *
	 * @return string
	 */
	public static function getOurScriptName ()
	{
		return self::getScriptNameFromUrl( $_SERVER['PHP_SELF'] );
	}

	/**
	 * Creates a signature
	 *
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function make ( $strScriptName, $arrParams, $strSecretKey )
	{
		$arrFlatParams = self::makeFlatParamsArray($arrParams);
		return md5( self::makeSigStr($strScriptName, $arrFlatParams, $strSecretKey) );
	}

	/**
	 * Verifies the signature
	 *
	 * @param string $signature
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return bool
	 */
	public static function check ( $signature, $strScriptName, $arrParams, $strSecretKey )
	{
		return (string)$signature === self::make($strScriptName, $arrParams, $strSecretKey);
	}


	/**
	 * Returns a string, a hash of which coincide with the result of the make() method.
	 * WARNING: This method can be used only for debugging purposes!
	 *
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return string
	 */
	static function debug_only_SigStr ( $strScriptName, $arrParams, $strSecretKey ) {
		return self::makeSigStr($strScriptName, $arrParams, $strSecretKey);
	}


	private static function makeSigStr ( $strScriptName, array $arrParams, $strSecretKey ) {
		unset($arrParams['pg_sig']);
		
		ksort($arrParams);

		array_unshift($arrParams, $strScriptName);
		array_push   ($arrParams, $strSecretKey);

		return join(';', $arrParams);
	}
	
	private static function makeFlatParamsArray ( $arrParams, $parent_name = '' )
	{
		$arrFlatParams = array();
		$i = 0;
		foreach ( $arrParams as $key => $val ) {
			
			$i++;
			if ( 'pg_sig' == $key )
				continue;
				
			/**
			 * Имя делаем вида tag001subtag001
			 * Чтобы можно было потом нормально отсортировать и вложенные узлы не запутались при сортировке
			 */
			$name = $parent_name . $key . sprintf('%03d', $i);

			if (is_array($val) ) {
				$arrFlatParams = array_merge($arrFlatParams, self::makeFlatParamsArray($val, $name));
				continue;
			}

			$arrFlatParams += array($name => (string)$val);
		}

		return $arrFlatParams;
	}

	/********************** singing XML ***********************/

	/**
	 * make the signature for XML
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function makeXML ( $strScriptName, $xml, $strSecretKey )
	{
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::make($strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Verifies the signature of XML
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return bool
	 */
	public static function checkXML ( $strScriptName, $xml, $strSecretKey )
	{
		if ( ! $xml instanceof SimpleXMLElement ) {
			$xml = new SimpleXMLElement($xml);
		}
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::check((string)$xml->pg_sig, $strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Returns a string, a hash of which coincide with the result of the makeXML() method.
	 * WARNING: This method can be used only for debugging purposes!
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function debug_only_SigStrXML ( $strScriptName, $xml, $strSecretKey )
	{
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::makeSigStr($strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Returns flat array of XML params
	 *
	 * @param (string|SimpleXMLElement) $xml
	 * @return array
	 */
	private static function makeFlatParamsXML ( $xml, $parent_name = '' )
	{
		if ( ! $xml instanceof SimpleXMLElement ) {
			$xml = new SimpleXMLElement($xml);
		}

		$arrParams = array();
		$i = 0;
		foreach ( $xml->children() as $tag ) {
			
			$i++;
			if ( 'pg_sig' == $tag->getName() )
				continue;
				
			/**
			 * Имя делаем вида tag001subtag001
			 * Чтобы можно было потом нормально отсортировать и вложенные узлы не запутались при сортировке
			 */
			$name = $parent_name . $tag->getName().sprintf('%03d', $i);

			if ( $tag->children()->count() > 0 ) {
				$arrParams = array_merge($arrParams, self::makeFlatParamsXML($tag, $name));
				continue;
			}

			$arrParams += array($name => (string)$tag);
		}

		return $arrParams;
	}
}
?>