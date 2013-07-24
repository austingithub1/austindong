<?php
namespace Destiny\Action\Web\Order;

use Destiny\Common\Service\AuthenticationService;
use PayPal\EBLBaseComponents\PaymentRequestInfoType;
use PayPal\CoreComponentTypes\BasicAmountType;
use PayPal\EBLBaseComponents\AddressType;
use PayPal\EBLBaseComponents\ActivationDetailsType;
use PayPal\EBLBaseComponents\BillingPeriodDetailsType;
use PayPal\EBLBaseComponents\CreateRecurringPaymentsProfileRequestDetailsType;
use PayPal\EBLBaseComponents\CreditCardDetailsType;
use PayPal\EBLBaseComponents\RecurringPaymentsProfileDetailsType;
use PayPal\EBLBaseComponents\ScheduleDetailsType;
use PayPal\PayPalAPI\CreateRecurringPaymentsProfileReq;
use PayPal\PayPalAPI\CreateRecurringPaymentsProfileRequestType;
use PayPal\PayPalAPI\GetExpressCheckoutDetailsReq;
use PayPal\PayPalAPI\GetExpressCheckoutDetailsRequestType;
use PayPal\Service\PayPalAPIInterfaceServiceService;
use PayPal\EBLBaseComponents\DoExpressCheckoutPaymentRequestDetailsType;
use PayPal\EBLBaseComponents\PaymentDetailsType;
use PayPal\PayPalAPI\DoExpressCheckoutPaymentReq;
use PayPal\PayPalAPI\DoExpressCheckoutPaymentRequestType;
use Destiny\Common\Application;
use Destiny\Common\Session;
use Destiny\Common\Utils\Http;
use Destiny\Common\Service\SubscriptionsService;
use Destiny\Common\Service\OrdersService;
use Destiny\Common\Config;
use Destiny\Common\Utils\Date;
use Destiny\Common\ViewModel;
use Destiny\Common\AppException;
use Destiny\Common\Annotation\Action;
use Destiny\Common\Annotation\Route;
use Destiny\Common\Annotation\HttpMethod;
use Destiny\Common\Annotation\Secure;
use Destiny\Common\Commerce\OrderStatus;
use Destiny\Common\Commerce\PaymentStatus;
use Destiny\Common\Commerce\SubscriptionStatus;

/**
 * @Action
 */
class Complete {
	
	/**
	 * Unique checkout token
	 *
	 * @var string
	 */
	protected $checkoutId = '';

	/**
	 * @Route ("/order/complete")
	 * @Secure ({"USER"})
	 *
	 * We were redirected here from PayPal after the buyer approved/cancelled the payment
	 * @param array $params
	 */
	public function execute(array $params, ViewModel $model) {
		$this->checkoutId = Session::get ( 'checkoutId' );
		$ordersService = OrdersService::instance ();
		$log = Application::instance ()->getLogger ();
		
		// Make sure our checkoutId is valid
		if (! isset ( $params ['checkoutId'] ) || empty ( $this->checkoutId ) || $this->checkoutId != $params ['checkoutId']) {
			// If we have an invalid checkout token.
			// Find the order, if its Complete, forward the user to the profile page
			if (isset ( $params ['orderId'] )) {
				$order = $ordersService->getOrderById ( $params ['orderId'] );
				// Make sure the order is for this user
				if (! empty ( $order ) && $order ['userId'] == Session::get ( 'userId' ) && strcasecmp ( $order ['state'], 'Completed' ) === 0) {
					// @TODO this should be done better
					Http::header ( Http::HEADER_LOCATION, '/profile/subscription' );
					die ();
				}
			}
			$model->error = new AppException ( 'Invalid checkout token' );
			return 'ordererror';
		}
		if (! isset ( $params ['token'] ) || empty ( $params ['token'] )) {
			$model->error = new AppException ( 'Invalid token' );
			return 'ordererror';
		}
		if (! isset ( $params ['success'] )) {
			$model->error = new AppException ( 'Invalid success response' );
			return 'ordererror';
		}
		if (! isset ( $params ['orderId'] )) {
			$model->error = new AppException ( 'Invalid orderId' );
			return 'ordererror';
		}
		
		// The token from paypal
		$token = $params ['token'];
		
		// Get | Build the order | Dirty
		$order = $ordersService->getOrderById ( $params ['orderId'] );
		if (empty ( $order )) {
			$model->error = new AppException ( 'Invalid order record' );
			return 'ordererror';
		}
		if ($order ['state'] != 'New') {
			$model->error = new AppException ( 'Invalid order status' );
			return 'ordererror';
		}
		$order ['items'] = $ordersService->getOrderItems ( $order ['orderId'] );
		$subscription = SubscriptionsService::instance ()->getSubscriptionType ( $order ['items'] [0] ['itemSku'] );
		$paymentProfile = $ordersService->getPaymentProfileByOrderId ( $order ['orderId'] );
		// END REALLY DIRTY
		
		// If we got a failed response URL
		if ($params ['success'] == '0' || $params ['success'] == 'false' || $params ['success'] === false) {
			$ordersService->updateOrderState ( $order ['orderId'], 'Error' );
			// Also set the profile state to error
			if (! empty ( $paymentProfile )) {
				$ordersService->updatePaymentProfileState ( $paymentProfile ['profileId'], 'Error' );
			}
			$model->error = new AppException ( 'Order request failed' );
			return 'ordererror';
		}
		
		// Get the checkout info
		$ecResponse = $this->retrieveCheckoutInfo ( $token );
		if (! isset ( $ecResponse ) || $ecResponse->Ack != 'Success') {
			$ordersService->updateOrderState ( $order ['orderId'], 'Error' );
			$model->error = new AppException ( 'Failed to retrieve express checkout details' );
			return 'ordererror';
		}
		
		// Is done after the success check
		if (! isset ( $params ['PayerID'] ) || empty ( $params ['PayerID'] )) {
			$model->error = new AppException ( 'Invalid PayerID' );
			return 'ordererror';
		}
		
		// Point of no return - we only every want a person to get here if their order was a successful sequence
		Session::set ( 'token' );
		Session::set ( 'orderId' );
		Session::set ( 'checkoutId' );
		
		// RECURRING PAYMENT
		if (! empty ( $paymentProfile )) {
			$createRPProfileResponse = $this->createRecurringPaymentProfile ( $paymentProfile, $token, $subscription );
			if (! isset ( $createRPProfileResponse ) || $createRPProfileResponse->Ack != 'Success') {
				$ordersService->updateOrderState ( $order ['orderId'], 'Error' );
				$model->error = new AppException ( 'Failed to create recurring payment request' );
				return 'ordererror';
			}
			$paymentProfileId = $createRPProfileResponse->CreateRecurringPaymentsProfileResponseDetails->ProfileID;
			$paymentStatus = $createRPProfileResponse->CreateRecurringPaymentsProfileResponseDetails->ProfileStatus;
			if (empty ( $paymentProfileId )) {
				$ordersService->updateOrderState ( $order ['orderId'], 'Error' );
				$model->error = new AppException ( 'Invalid recurring payment profileId returned from Paypal' );
				return 'ordererror';
			}
			// Set the payment profile to active, and paymetProfileId
			$ordersService->updatePaymentProfileStatus ( $paymentProfile ['profileId'], $paymentProfileId, $paymentStatus );
		}
		
		// Complete the checkout
		$DoECRequestDetails = new DoExpressCheckoutPaymentRequestDetailsType ();
		$DoECRequestDetails->PayerID = $params ['PayerID'];
		$DoECRequestDetails->Token = $token;
		$DoECRequestDetails->PaymentAction = 'Sale';
		
		$paymentDetails = new PaymentDetailsType ();
		$paymentDetails->OrderTotal = new BasicAmountType ( $order ['currency'], $order ['amount'] );
		$paymentDetails->NotifyURL = Config::$a ['paypal'] ['api'] ['ipn'];
		$DoECRequestDetails->PaymentDetails [0] = $paymentDetails;
		
		$DoECRequest = new DoExpressCheckoutPaymentRequestType ();
		$DoECRequest->DoExpressCheckoutPaymentRequestDetails = $DoECRequestDetails;
		$DoECReq = new DoExpressCheckoutPaymentReq ();
		$DoECReq->DoExpressCheckoutPaymentRequest = $DoECRequest;
		
		$paypalService = new PayPalAPIInterfaceServiceService ();
		$DoECResponse = $paypalService->DoExpressCheckoutPayment ( $DoECReq );
		
		$payments = array ();
		if (isset ( $DoECResponse ) && $DoECResponse->Ack == 'Success') {
			if (isset ( $DoECResponse->DoExpressCheckoutPaymentResponseDetails->PaymentInfo )) {
				$orderStatus = OrderStatus::COMPLETED;
				for($i = 0; $i < count ( $DoECResponse->DoExpressCheckoutPaymentResponseDetails->PaymentInfo ); ++ $i) {
					$paymentInfo = $DoECResponse->DoExpressCheckoutPaymentResponseDetails->PaymentInfo [$i];
					$payment = array ();
					$payment ['orderId'] = $order ['orderId'];
					$payment ['payerId'] = $params ['PayerID'];
					$payment ['amount'] = $paymentInfo->GrossAmount->value;
					$payment ['currency'] = $paymentInfo->GrossAmount->currencyID;
					$payment ['transactionId'] = $paymentInfo->TransactionID;
					$payment ['transactionType'] = $paymentInfo->TransactionType;
					$payment ['paymentType'] = $paymentInfo->PaymentType;
					$payment ['paymentStatus'] = $paymentInfo->PaymentStatus;
					$payment ['paymentDate'] = Date::getDateTime ( $paymentInfo->PaymentDate )->format ( 'Y-m-d H:i:s' );
					if ($paymentInfo->PaymentStatus != PaymentStatus::COMPLETED) {
						$orderStatus = OrderStatus::PENDING;
					}
					$ordersService->addOrderPayment ( $payment );
					$payments [] = $payment;
				}
				$ordersService->updateOrderState ( $order ['orderId'], $orderStatus );
			} else {
				$ordersService->updateOrderState ( $order ['orderId'], 'Error' );
				$model->error = new AppException ( sprintf ( 'No payments for express checkout order %s', $order ['orderId'] ) );
				return 'ordererror';
			}
		} else {
			$ordersService->updateOrderState ( $order ['orderId'], 'Error' );
			$log->error ( $DoECResponse->Errors [0]->LongMessage );
			$model->error = new AppException ( 'Unable to retrieve response from Paypal' );
			return 'ordererror';
		}
		
		// Create / adjust subscription
		if ($orderStatus == OrderStatus::COMPLETED) {
			$subscriptionStatus = SubscriptionStatus::ACTIVE;
		} else {
			$subscriptionStatus = SubscriptionStatus::PENDING;
		}
		$start = Date::getDateTime ( 'NOW' );
		$end = Date::getDateTime ( 'NOW' );
		$end->modify ( '+' . $subscription ['billingFrequency'] . ' ' . strtolower ( $subscription ['billingPeriod'] ) );
		$subscriptionId = SubscriptionsService::instance ()->addSubscription ( $order ['userId'], $start->format ( 'Y-m-d H:i:s' ), $end->format ( 'Y-m-d H:i:s' ), $subscriptionStatus, (! empty ( $paymentProfile )), 'destiny.gg' );
		if (! empty ( $paymentProfile )) {
			SubscriptionsService::instance ()->updateSubscriptionPaymentProfile ( $subscriptionId, $paymentProfile ['profileId'], true );
		}
		
		// Add the subscriber role, this is just for UI
		if ($orderStatus == OrderStatus::COMPLETED) {
			AuthenticationService::instance ()->flagUserForUpdate ( $order ['userId'] );
		}
		
		// Show the order complete screen
		$model->order = $order;
		$model->subscription = $subscription;
		$model->paymentProfile = $paymentProfile;
		return 'ordercomplete';
	}

	/**
	 * Retrieve the checkout instance from paypal
	 *
	 * @return \PayPalAPI\GetExpressCheckoutDetailsResponseType
	 */
	protected function retrieveCheckoutInfo($token) {
		$paypalService = new PayPalAPIInterfaceServiceService ();
		$getExpressCheckoutReq = new GetExpressCheckoutDetailsReq ();
		$getExpressCheckoutReq->GetExpressCheckoutDetailsRequest = new GetExpressCheckoutDetailsRequestType ( $token );
		return $paypalService->GetExpressCheckoutDetails ( $getExpressCheckoutReq );
	}

	/**
	 * Create a Paypal recurring payment profile
	 *
	 * @param array $order
	 * @param string $token
	 * @param array $subscription
	 * @return \PayPalAPI\CreateRecurringPaymentsProfileResponseType
	 */
	protected function createRecurringPaymentProfile(array $paymentProfile, $token, array $subscription) {
		$billingStartDate = Date::getDateTime ( $paymentProfile ['billingStartDate'] );
		
		$RPProfileDetails = new RecurringPaymentsProfileDetailsType ();
		
		$RPProfileDetails->SubscriberName = Session::getCredentials ()->getUsername (); // This should be passed in
		$RPProfileDetails->BillingStartDate = $billingStartDate->format ( \DateTime::ATOM );
		$RPProfileDetails->ProfileReference = $paymentProfile ['userId'] . '-' . $paymentProfile ['orderId'];
		
		$paymentBillingPeriod = new BillingPeriodDetailsType ();
		$paymentBillingPeriod->BillingFrequency = $paymentProfile ['billingFrequency'];
		$paymentBillingPeriod->BillingPeriod = $paymentProfile ['billingPeriod'];
		$paymentBillingPeriod->Amount = new BasicAmountType ( $paymentProfile ['currency'], $paymentProfile ['amount'] );
		
		$scheduleDetails = new ScheduleDetailsType ();
		$scheduleDetails->Description = $subscription ['agreement'];
		$scheduleDetails->PaymentPeriod = $paymentBillingPeriod;
		
		$createRPProfileRequestDetail = new CreateRecurringPaymentsProfileRequestDetailsType ();
		$createRPProfileRequestDetail->Token = $token;
		$createRPProfileRequestDetail->ScheduleDetails = $scheduleDetails;
		$createRPProfileRequestDetail->RecurringPaymentsProfileDetails = $RPProfileDetails;
		
		$createRPProfileRequest = new CreateRecurringPaymentsProfileRequestType ();
		$createRPProfileRequest->CreateRecurringPaymentsProfileRequestDetails = $createRPProfileRequestDetail;
		$createRPProfileReq = new CreateRecurringPaymentsProfileReq ();
		$createRPProfileReq->CreateRecurringPaymentsProfileRequest = $createRPProfileRequest;
		
		$paypalService = new PayPalAPIInterfaceServiceService ();
		return $paypalService->CreateRecurringPaymentsProfile ( $createRPProfileReq );
	}

}