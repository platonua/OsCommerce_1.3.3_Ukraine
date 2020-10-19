<?php

//$log = __DIR__ . '/callback.log';

if (!$_POST)
    die("ERROR: Empty POST");

// log callback data
//file_put_contents($log, var_export($_POST, 1) . "\n\n", FILE_APPEND);


chdir('../../../../');
require ('includes/application_top.php');
include(DIR_WS_LANGUAGES . $language . '/' . FILENAME_CHECKOUT_PROCESS);

$callbackParams = $_POST;

// generate signature from callback params
$sign = md5(strtoupper(
                strrev($callbackParams['email']) .
                MODULE_PAYMENT_PLATON_PASSWORD .
                $callbackParams['order'] .
                strrev(substr($callbackParams['card'], 0, 6) . substr($callbackParams['card'], -4))
        ));

// verify signature
if ($callbackParams['sign'] !== $sign) {
    // log failure
    //file_put_contents($log, date('Y-m-d H:i:s ') . "  Invalid signature" . "\n\n", FILE_APPEND);
    // answer with fail response
    die("ERROR: Invalid signature");
} else {
    // log success
    //file_put_contents($log, date('Y-m-d H:i:s ') . "  Callback signature OK" . "\n\n", FILE_APPEND);

    // do processing stuff
    switch ($callbackParams['status']) {
        case 'SALE':
            $order_id = $callbackParams['order'];
            require(DIR_WS_CLASSES . 'order.php');
            $order = new order($order_id);
            if (!count($order->products)) {
                //file_put_contents($log, date('Y-m-d H:i:s ') . "  ERROR: wrong order_id: $order_id" . "\n\n", FILE_APPEND);
                die('ERROR: wrong order_id');
            }

            // initialized for the email confirmation
            $products_ordered = '';
            $subtotal = 0;
            $total_tax = 0;

            for ($i = 0, $n = sizeof($order->products); $i < $n; $i++) {
                // Stock Update - Joao Correia
                if (STOCK_LIMITED == 'true') {
                    if (DOWNLOAD_ENABLED == 'true') {
                        $stock_query_raw = "SELECT products_quantity, pad.products_attributes_filename
                                FROM " . TABLE_PRODUCTS . " p
                                LEFT JOIN " . TABLE_PRODUCTS_ATTRIBUTES . " pa
                                ON p.products_id=pa.products_id
                                LEFT JOIN " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
                                ON pa.products_attributes_id=pad.products_attributes_id
                                WHERE p.products_id = '" . tep_get_prid($order->products[$i]['id']) . "'";
                        // Will work with only one option for downloadable products
                        // otherwise, we have to build the query dynamically with a loop
                        $products_attributes = $order->products[$i]['attributes'];
                        if (is_array($products_attributes)) {
                            $stock_query_raw .= " AND pa.options_id = '" . $products_attributes[0]['option_id'] . "' AND pa.options_values_id = '" . $products_attributes[0]['value_id'] . "'";
                        }
                        $stock_query = tep_db_query($stock_query_raw);
                    } else {
                        $stock_query = tep_db_query("select products_quantity from " . TABLE_PRODUCTS . " where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
                    }
                    if (tep_db_num_rows($stock_query) > 0) {
                        $stock_values = tep_db_fetch_array($stock_query);
                        // do not decrement quantities if products_attributes_filename exists
                        if ((DOWNLOAD_ENABLED != 'true') || (!$stock_values['products_attributes_filename'])) {
                            $stock_left = $stock_values['products_quantity'] - $order->products[$i]['qty'];
                        } else {
                            $stock_left = $stock_values['products_quantity'];
                        }
                        tep_db_query("update " . TABLE_PRODUCTS . " set products_quantity = '" . $stock_left . "' where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
                        if (($stock_left < 1) && (STOCK_ALLOW_CHECKOUT == 'false')) {
                            tep_db_query("update " . TABLE_PRODUCTS . " set products_status = '0' where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
                        }
                    }
                }

                // Update products_ordered (for bestsellers list)
                tep_db_query("update " . TABLE_PRODUCTS . " set products_ordered = products_ordered + " . sprintf('%d', $order->products[$i]['qty']) . " where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");

                //------insert customer choosen option to order--------
                $attributes_exist = '0';
                $products_ordered_attributes = '';
                if (isset($order->products[$i]['attributes'])) {
                    $attributes_exist = '1';
                    for ($j = 0, $n2 = sizeof($order->products[$i]['attributes']); $j < $n2; $j++) {
                        if (DOWNLOAD_ENABLED == 'true') {
                            $attributes_query = "select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix, pad.products_attributes_maxdays, pad.products_attributes_maxcount , pad.products_attributes_filename
                                   from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa
                                   left join " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
                                   on pa.products_attributes_id=pad.products_attributes_id
                                   where pa.products_id = '" . $order->products[$i]['id'] . "'
                                   and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "'
                                   and pa.options_id = popt.products_options_id
                                   and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "'
                                   and pa.options_values_id = poval.products_options_values_id
                                   and popt.language_id = '" . $languages_id . "'
                                   and poval.language_id = '" . $languages_id . "'";
                            $attributes = tep_db_query($attributes_query);
                        } else {
                            $attributes = tep_db_query("select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa where pa.products_id = '" . $order->products[$i]['id'] . "' and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "' and pa.options_id = popt.products_options_id and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "' and pa.options_values_id = poval.products_options_values_id and popt.language_id = '" . $languages_id . "' and poval.language_id = '" . $languages_id . "'");
                        }
                        $attributes_values = tep_db_fetch_array($attributes);

                        $products_ordered_attributes .= "\n\t" . $attributes_values['products_options_name'] . ' ' . $attributes_values['products_options_values_name'];
                    }
                }
                //------insert customer choosen option eof ----
                $total_weight += ($order->products[$i]['qty'] * $order->products[$i]['weight']);
                $total_tax += tep_calculate_tax($total_products_price, $products_tax) * $order->products[$i]['qty'];
                $total_cost += $total_products_price;

                $products_ordered .= $order->products[$i]['qty'] . ' x ' . $order->products[$i]['name'] . ' (' . $order->products[$i]['model'] . ') = ' . $currencies->display_price($order->products[$i]['final_price'], $order->products[$i]['tax'], $order->products[$i]['qty']) . $products_ordered_attributes . "\n";
            }

            // lets start with the email confirmation
            $email_order = STORE_NAME . "\n" .
                    EMAIL_SEPARATOR . "\n" .
                    EMAIL_TEXT_ORDER_NUMBER . ' ' . $order_id . "\n" .
                    EMAIL_TEXT_INVOICE_URL . ' ' . tep_href_link(FILENAME_ACCOUNT_HISTORY_INFO, 'order_id=' . $order_id, 'SSL', false) . "\n" .
                    EMAIL_TEXT_DATE_ORDERED . ' ' . strftime(DATE_FORMAT_LONG) . "\n\n";
            if ($order->info['comments']) {
                $email_order .= tep_db_output($order->info['comments']) . "\n\n";
            }
            $email_order .= EMAIL_TEXT_PRODUCTS . "\n" .
                    EMAIL_SEPARATOR . "\n" .
                    $products_ordered .
                    EMAIL_SEPARATOR . "\n";

            for ($i = 0, $n = sizeof($order_totals); $i < $n; $i++) {
                $email_order .= strip_tags($order_totals[$i]['title']) . ' ' . strip_tags($order_totals[$i]['text']) . "\n";
            }

            if ($order->content_type != 'virtual') {
                $sendto = array_merge($order->delivery, array('address_format_id' => $order->delivery['format_id']));
                $email_order .= "\n" . EMAIL_TEXT_DELIVERY_ADDRESS . "\n" .
                        EMAIL_SEPARATOR . "\n" .
                        tep_address_label($order->customer['customer_id'], $sendto, 0, '', "\n") . "\n";
            }
            $billto = array_merge($order->billing, array('address_format_id' => $order->billing['format_id']));
            $email_order .= "\n" . EMAIL_TEXT_BILLING_ADDRESS . "\n" .
                    EMAIL_SEPARATOR . "\n" .
                    tep_address_label($order->customer['customer_id'], $billto, 0, '', "\n") . "\n\n";
            // selected payment module
            $email_order .= EMAIL_TEXT_PAYMENT_METHOD . "\n" .
                    EMAIL_SEPARATOR . "\n";
            $email_order .= "Platon\n\n";

            tep_mail($order->customer['firstname'] . ' ' . $order->customer['lastname'], $order->customer['email_address'], EMAIL_TEXT_SUBJECT, $email_order, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);

            // send emails to other people
            if (SEND_EXTRA_ORDER_EMAILS_TO != '') {
                tep_mail('', SEND_EXTRA_ORDER_EMAILS_TO, EMAIL_TEXT_SUBJECT, $email_order, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
            }
            tep_db_query("update " . TABLE_ORDERS . " set orders_status = '2', last_modified = now() where orders_id = '" . (int) $order_id . "'");

            $sql_data_array = array('orders_id' => $order_id,
                'orders_status_id' => 2,
                'date_added' => 'now()',
                'customer_notified' => (SEND_EMAILS == 'true') ? '1' : '0',
                'comments' => 'Paid by Platon');

            tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
            //file_put_contents($log, date('Y-m-d H:i:s ') . "  Order {$callbackParams['order']} processed as successfull sale" . "\n\n", FILE_APPEND);
            break;
        case 'REFUND':
            $order_id = $callbackParams['order'];
            tep_db_query("update " . TABLE_ORDERS . " set orders_status = '1', last_modified = now() where orders_id = '" . (int) $order_id . "'");

            $sql_data_array = array('orders_id' => $order_id,
                'orders_status_id' => 1,
                'date_added' => 'now()',
                'customer_notified' => '0',
                'comments' => 'Refunded to customer by Platon');
			tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
            //file_put_contents($log, date('Y-m-d H:i:s ') . "  Order {$callbackParams['order']} processed as successfull refund" . "\n\n", FILE_APPEND);
            break;
        case 'CHARGEBACK':
            //file_put_contents($log, date('Y-m-d H:i:s ') . "  Order {$callbackParams['order']} processed as successfull chargeback" . "\n\n", FILE_APPEND);
            break;
        default:
            //file_put_contents($log, date('Y-m-d H:i:s ') . "  Invalid callback data" . "\n\n", FILE_APPEND);
            die("ERROR: Invalid callback data");
    }

    // answer with success response
    exit("OK");
}