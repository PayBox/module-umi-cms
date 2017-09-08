<?php
$FORMS = Array();

$FORMS['form_block'] = <<<END

<form action="https://paybox.kz/payment.php" method="post">	

	   <input type="hidden" name="pg_merchant_id" value="%pg_merchant_id%" />
	   <input type="hidden" name="pg_order_id" value="%pg_order_id%" />
	   <input type="hidden" name="pg_currency" value="%pg_currency%" />
	   <input type="hidden" name="pg_amount" value="%pg_amount%" />
	   <input type="hidden" name="pg_lifetime" value="%pg_lifetime%" />
	   <input type="hidden" name="pg_testing_mode" value="%pg_testing_mode%" />
	   <input type="hidden" name="pg_description" value="%pg_description%" />
	   <input type="hidden" name="pg_user_ip" value="%pg_user_ip%" />		
	
	   <input type="hidden" name="pg_language" value="%pg_language%" />	
	   <input type="hidden" name="pg_check_url" value="%pg_check_url%" />	
	   <input type="hidden" name="pg_result_url" value="%pg_result_url%" />	
	   <input type="hidden" name="pg_request_method" value="%pg_request_method%" />	
	   <input type="hidden" name="pg_success_url" value="%pg_success_url%" />	
	   <input type="hidden" name="pg_failure_url" value="%pg_failure_url%" />	
	   <input type="hidden" name="pg_salt" value="%pg_salt%" />	
	   
	   
	   <input type="hidden" name="pg_user_phone" value="%pg_user_phone%" />
	   <input type="hidden" name="pg_user_email" value="%pg_user_email%" />	
	   <input type="hidden" name="pg_user_contact_email" value="%pg_user_contact_email%" />	
	   <input type="hidden" name="pg_sig" value="%pg_sig%" />	

	   <p>
		Нажмите кнопку "Оплатить" для перехода на сайт платежной системы <strong>PayBox</strong>.
	</p>        

	<p>
		<input type="submit" value="Оплатить" />
	</p>
</form>
END;

?>