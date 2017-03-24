<?php

namespace App\Http\Controllers;

use App\Repositories\CartRepository;
use App\Services\CheckoutService;
use Gloudemans\Shoppingcart\Facades\Cart;
use PayPal;
use Redirect;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Ecommerce-CMS
     *
     * Copyright (C) 2014 - 2015  Tihomir Blazhev.
     *
     * LICENSE
     *
     * Ecommerce-cms is released with dual licensing, using the GPL v3 (license-gpl3.txt) and the MIT license (license-mit.txt).
     * You don't have to do anything special to choose one license or the other and you don't have to notify anyone which license you are using.
     * Please see the corresponding license file for details of these licenses.
     * You are free to use, modify and distribute this software, but all copyright information must remain.
     *
     * @package     ecommerce-cms
     * @copyright   Copyright (c) 2014 through 2015, Tihomir Blazhev
     * @license     http://opensource.org/licenses/MIT  MIT License
     * @version     1.0.0
     * @author      Tihomir Blazhev <raylight75@gmail.com>
     */

    /**
     *
     * Payment Class
     * Payment Class for managing PayPal payments.
     * @package ecommerce-cms
     * @category Base Class
     * @author Tihomir Blazhev <raylight75@gmail.com>
     * @link https://raylight75@bitbucket.org/raylight75/ecommerce-cms.git
     */

    private $_apiContext;

    public function __construct(CartRepository $cart, CheckoutService $checkout)
    {
        $this->cart = $cart;
        $this->checkout = $checkout;
        $this->_apiContext = PayPal::ApiContext(
            config('services.paypal.client_id'),
            config('services.paypal.secret'));

        $this->_apiContext->setConfig(array(
            'mode' => 'sandbox',
            'service.EndPoint' => 'https://api.sandbox.paypal.com',
            'http.ConnectionTimeOut' => 30,
            'log.LogEnabled' => true,
            'log.FileName' => storage_path('logs/paypal.log'),
            'log.LogLevel' => 'FINE'
        ));

    }

    public function getCheckout(Request $request)
    {
        $cart = Cart::instance(auth()->id())->content();
        //dd($cart);
        $payer = PayPal::Payer();
        $payer->setPaymentMethod('paypal');
        foreach ($cart as $item) {
            $subtotal = $item->subtotal;
            $data[$item->id] = PayPal::Item();
            $data[$item->id]->setName($item->name)
                ->setDescription($item->name)
                ->setCurrency("USD")
                ->setQuantity((int)$item->qty)
                ->setPrice($item->price);
        }
        $items = (array_values($data));
        $itemList = PayPal::ItemList();
        //$itemList->addItem($data[50]);
        $itemList->setItems($items);
        $details = PayPal::Details();

        //taxes
        $tax = $this->checkout->checkoutShow($request);
        $total = $this->cart->setCart();
        $vat_total = $total['grandTotal'] * $tax['vat'];
        $grand_total = $total['grandTotal'] + $tax['shippings']->rate + $vat_total;

        //dd($grand_total);

        $details->setShipping($tax['shippings']->rate)
            ->setTax($vat_total)
            ->setSubTotal((int)$total['grandTotal']);
        //Payment Amount
        $amount = PayPal::Amount();
        $amount->setCurrency("USD")
            ->setTotal($grand_total)
            ->setDetails($details);

        $transaction = PayPal::Transaction();
        $transaction->setAmount($amount)
            ->setItemList($itemList)
            ->setInvoiceNumber(uniqid())
            ->setDescription('What are you selling?');

        $redirectUrls = PayPal:: RedirectUrls();
        $redirectUrls->setReturnUrl(url('checkout/done'));
        $redirectUrls->setCancelUrl(url('checkout/cancel'));

        $payment = PayPal::Payment();
        $payment->setIntent('sale');
        $payment->setPayer($payer);
        $payment->setRedirectUrls($redirectUrls);
        $payment->setTransactions(array($transaction));

        $profile = $this->createWebProfile();
        $payment->setExperienceProfileId($profile);

        $response = $payment->create($this->_apiContext);
        $redirectUrl = $response->links[1]->href;

        return Redirect::to($redirectUrl);
    }

    public function getDone(Request $request)
    {
        $id = $request->get('paymentId');
        $token = $request->get('token');
        $payer_id = $request->get('PayerID');

        $payment = PayPal::getById($id, $this->_apiContext);

        $paymentExecution = PayPal::PaymentExecution();

        $paymentExecution->setPayerId($payer_id);
        $result = $payment->execute($paymentExecution, $this->_apiContext);
        $customer = $result->getPayer()
            ->getPayerInfo();
        dd($customer->email);
        // Clear the shopping cart, write to database, send notifications, etc.

        // Thank the user for the purchase
        return view('frontend/payment')->with('executePayment', $result);
    }

    public function getCancel()
    {
        // Curse and humiliate the user for cancelling this most sacred payment (yours)
        $executePayment = null;
        return view('frontend/payment')->with('executePayment', $executePayment);
    }

    public function createWebProfile()
    {

        //$flowConfig = PayPal::FlowConfig();
        $presentation = PayPal::Presentation();
        //$inputFields = PayPal::InputFields();
        $webProfile = PayPal::WebProfile();
        //$flowConfig->setLandingPageType("Billing"); //Set the page type

        $presentation->setLogoImage("https://www.example.com/images/logo.jpg")->setBrandName("Example ltd"); //NB: Paypal recommended to use https for the logo's address and the size set to 190x60.

        //$inputFields->setAllowNote(true)->setNoShipping(1)->setAddressOverride(1);

        $webProfile->setName("Example " . uniqid())
            //->setFlowConfig($flowConfig)
            // Parameters for style and presentation.
            ->setPresentation($presentation);
        // Parameters for input field customization.
        //->setInputFields($inputFields);

        $createProfileResponse = $webProfile->create($this->_apiContext);

        return $createProfileResponse->getId(); //The new webprofile's id
    }
}