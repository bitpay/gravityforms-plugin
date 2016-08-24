# Using the BitPay plugin for Gravity Forms

## Prerequisites

* Last Version Tested: 1.8

You must have a BitPay merchant account to use this plugin.  It's free to [sign-up for a BitPay merchant account](https://bitpay.com/start).


## Server Requirements

* [Wordpress](https://wordpress.org/about/requirements/) >= 4.6 (Older versions may work, but we do not test against those)
* [GMP](http://php.net/manual/en/book.gmp.php) or [BCMath](http://php.net/manual/en/book.bc.php) You may have to install GMP as most servers do not come with it, but generally BCMath is already included.
* [GravityForms](http://www.gravityhelp.com/) >= 2.0.6
* [mcrypt](http://us2.php.net/mcrypt)
* [OpenSSL](http://us2.php.net/openssl) Must be compiled with PHP
* PHP >= 5.4

## Installation

**From Downloadable Archive:**

Visit the [Releases](https://github.com/bitpay/gravityforms-plugin/releases/latest) page of
this repository and download the latest version. Once this is done, you can just
go to Wordpress's Adminstration Panels > Plugins > Add New > Upload Plugin, select the downloaded archive and click Install Now.
After the plugin is installed, click on Activate.

**WARNING:** It is good practice to backup your database before installing plugins. Please make sure you create backups.

**NOTE:** Your Maximum File Upload Size located inside your php.ini may prevent you from uploading the plugin if it is less than 2MB. If this is the case just extract the contents of the Release into your Wordpress's wp-content/plugins folder.

**From source code:**

Run the following command to build the plugin folder:

```bash
./setup
```

Copy and paste the bitpay-gravityforms-plugin folder into your WordPress's wp-content/plugins folder

## Configuration

Configuration can be done using the Administrator section of Wordpress.
Once Logged in, you will find the configuration settings under **Forms > Settings > BitPay Payments**.
Alternatively, you can also get to the configuration settings via Plugins and clicking the Settings link for this plugin.

![BitPay Settings](https://raw.githubusercontent.com/aleitner/aleitner.github.io/master/gravityforms/fullSettings2.png "BitPay Settings")

Here your will need to create a [pairing code](https://bitpay.com/api-tokens) using
your BitPay merchant account. Once you have a Pairing Code, put the code in the
Pairing Code field:

![Pairing Code field](https://raw.githubusercontent.com/aleitner/aleitner.github.io/master/gravityforms/pairingCode2.png "Pairing Code field")

On success, you'll receive a token:

![BitPay Token](https://raw.githubusercontent.com/aleitner/aleitner.github.io/master/gravityforms/paired2.png "Bitpay Token")

**NOTE:** Pairing Codes are only valid for a short period of time. If it expires
before you get to use it, you can always create a new one and pair with it.

**NOTE:** You will only need to do this once since each time you do this, the
extension will generate public and private keys that are used to identify you
when using the API.

You are also able to configure how BitPay's IPN (Instant Payment Notifications)
changes the order in your Gravity Forms store.

![Invoice Settings](https://raw.githubusercontent.com/aleitner/aleitner.github.io/master/gravityforms/transactionSpeed.png "Invoice Settings")

When an invoice is paid this is the URL that your customers are taken to.

![Redirect URL](https://raw.githubusercontent.com/aleitner/aleitner.github.io/master/gravityforms/redirectUrl.png "Redirect URL")

Save your changes and you're good to go!

## Usage

Once enabled, your customers will be able to pay with Bitcoins. Once
they checkout they are redirected to a full screen BitPay invoice to pay for
the order.

As a merchant, the orders in your Gravity Forms store can be treated as any other
order. You may need to adjust the Invoice Settings depending on your order
fulfillment.

## GMP Installation

It is highly recommended you install GMP for this plugin to acheive maximum performance.

**Compile PHP with GMP:**

[http://php.net/manual/en/gmp.installation.php](http://php.net/manual/en/gmp.installation.php)

**Enable Extension:**

If the extension has been included with your PHP install, you only need to uncomment the line in the PHP ini configuration file.

On Windows:

```ini
; From
;extension=php_gmp.dll
; To
extension=php_gmp.dll
```

On Linux:

```ini
; From
;extension=gmp.so
; To
extension=gmp.so
```

On Ubuntu Specifically:

```bash
$ sudo apt-get update
$ sudo apt-get install php5-gmp
$ sudo php5enmod gmp

# Restart your server
```
