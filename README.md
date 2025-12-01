# Areeba MPGS WooCommerce Gateway

**Version:** 1.3.0  
**Author:** ChatGPT / Gemini  
**Website:** [https://nomaconcept.net](https://nomaconcept.net)

A fully functional WooCommerce payment gateway integration for **Areeba MPGS Hosted Checkout (HPP)**. This plugin allows customers to pay with credit/debit cards securely via Areeba, while WooCommerce manages orders, stock, and cart behavior.  

---

## Features

- Integrates Areeba MPGS HPP with WooCommerce.
- Initiates a secure checkout session with Areeba.
- Supports asynchronous webhook notifications to mark orders as paid.
- Automatically reduces product stock when payment is successful.
- Empties the customer’s cart after payment.
- Redirects customers back to the WooCommerce **thank-you page** after successful payment.

---

## Installation

1. Upload the plugin folder to your WordPress `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress “Plugins” menu.
3. Go to **WooCommerce > Settings > Payments** and enable **Areeba MPGS HPP**.
4. Fill in the required settings:
   - **Title** – Payment method name shown to customers.
   - **Merchant ID** – Your Areeba merchant ID.
   - **API Password** – Your Areeba API password.
   - **Webhook Secret** – Secret for verifying webhook notifications.

---

## Usage

1. Customer selects **Areeba MPGS HPP** at checkout.
2. WooCommerce creates the order and redirects the customer to Areeba’s hosted checkout page.
3. After completing payment, Areeba sends a **webhook notification** to:
