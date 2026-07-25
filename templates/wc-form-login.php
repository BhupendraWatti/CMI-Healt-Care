<?php
/**
 * WooCommerce Mobile-Based Login & Registration Form Override for CMI Healthcare
 * Overrides WooCommerce /my-account/ page with CMI's mobile-first auth UI.
 *
 * - Primary auth: Mobile number only (no password required – passwordless flow).
 * - New user: Provide Full Name + Mobile → account created + welcome SMS sent.
 * - Existing user: Mobile number only → logged in immediately.
 * - Alternative: Email + Password login (for admin / partners with existing accounts).
 * - OTP: Commented out until Airtel DLT OTP template is approved.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="cmi-wc-auth-wrapper" style="display: flex; justify-content: center; align-items: center; min-height: 480px; padding: 40px 15px; background: #f8fafc;">
    <div class="cmi-cmi-card-auth" style="width: 100%; max-width: 420px; background: #ffffff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; padding: 35px 30px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

        <h2 style="font-size: 24px; font-weight: 400; color: #444444; margin: 0 0 25px 0; text-align: center;">Login / Register</h2>

        <div id="cmi-auth-msg" class="cmi-msg" style="display:none; margin-bottom: 20px;"></div>

        <!-- 1. Mobile Phone Direct Auth Form (Passwordless) -->
        <form id="cmi-mobile-direct-form" class="cmi-auth-form" method="post">

            <div class="cmi-form-row" style="margin-bottom: 18px;">
                <label for="cmi-otp-mobile" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Phone number</label>
                <div class="cmi-phone-input-wrap" style="display: flex; border: 1px solid #cccccc; border-radius: 6px; overflow: hidden; height: 44px; background: #fff;">
                    <div style="background: #f3f4f6; border-right: 1px solid #cccccc; padding: 0 12px; display: flex; align-items: center; font-size: 14px; font-weight: 600; color: #4b5563;">
                        <span>🇮🇳 +91</span>
                    </div>
                    <input type="tel" id="cmi-otp-mobile" name="mobile" placeholder="10-digit mobile number" maxlength="15"
                           style="border: none; outline: none; padding: 10px 14px; font-size: 15px; width: 100%; box-shadow: none; background: transparent;" />
                </div>
            </div>

            <div class="cmi-form-row" style="margin-bottom: 18px;">
                <label for="cmi-otp-name" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">
                    Full Name <small style="font-weight: normal; color: #6b7280;">(Required for new registration)</small>
                </label>
                <input type="text" id="cmi-otp-name" name="name" placeholder="Enter your full name"
                       style="width: 100%; border: 1px solid #cccccc; border-radius: 6px; padding: 10px 14px; font-size: 14px; outline: none; box-sizing: border-box;" />
            </div>

            <div style="margin: 5px 0 22px 0; display: flex; align-items: flex-start; gap: 8px; font-size: 12px; color: #4b5563;">
                <input type="checkbox" id="cmi-terms-check" checked style="margin-top: 2px;" />
                <label for="cmi-terms-check" style="font-size: 12px; color: #4b5563; font-weight: normal; margin: 0;">
                    By submitting, you agree to the <a href="#" style="color: #0073aa; text-decoration: none;">Terms and Privacy Policy</a>
                </label>
            </div>

            <button type="submit" id="cmi-direct-auth-submit-btn" class="cmi-teal-btn"
                    style="width: 100%; background: #00a99d; color: #ffffff; border: none; border-radius: 6px; height: 46px; font-size: 15px; font-weight: 600; cursor: pointer; margin-bottom: 12px; transition: background 0.2s;">
                Submit
            </button>

            <button type="button" id="cmi-toggle-email-btn" class="cmi-teal-btn"
                    style="width: 100%; background: transparent; color: #00a99d; border: 2px solid #00a99d; border-radius: 6px; height: 46px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                Login with email instead
            </button>
        </form>

        <!-- 2. Email & Password Form (Alternative View for existing accounts) -->
        <form id="cmi-email-login-form" class="cmi-auth-form" style="display:none;" method="post">

            <div class="cmi-form-row" style="margin-bottom: 18px;">
                <label for="cmi-login-email" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Email address</label>
                <input type="email" id="cmi-login-email" placeholder="name@example.com"
                       style="width: 100%; border: 1px solid #cccccc; border-radius: 6px; padding: 10px 14px; font-size: 14px; outline: none; box-sizing: border-box;" />
            </div>

            <div class="cmi-form-row" style="margin-bottom: 22px;">
                <label for="cmi-login-password" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Password</label>
                <input type="password" id="cmi-login-password" placeholder="••••••••"
                       style="width: 100%; border: 1px solid #cccccc; border-radius: 6px; padding: 10px 14px; font-size: 14px; outline: none; box-sizing: border-box;" />
            </div>

            <button type="submit" class="cmi-teal-btn cmi-auth-submit-btn"
                    style="width: 100%; background: #00a99d; color: #ffffff; border: none; border-radius: 6px; height: 46px; font-size: 15px; font-weight: 600; cursor: pointer; margin-bottom: 12px;">
                Log In with Email
            </button>

            <button type="button" id="cmi-toggle-phone-btn" class="cmi-teal-btn"
                    style="width: 100%; background: transparent; color: #00a99d; border: 2px solid #00a99d; border-radius: 6px; height: 46px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                ← Back to Phone Login
            </button>
        </form>

        <!--
        ========================================================================
        FUTURE OTP INTEGRATION (COMMENTED UNTIL AIRTEL DLT OTP TEMPLATE APPROVED)
        ========================================================================
        <form id="cmi-mobile-otp-form" class="cmi-auth-form" style="display:none;">
            <div id="cmi-otp-step-2">
                <input type="text" id="cmi-otp-code" placeholder="123456" maxlength="6" />
                <button type="submit" id="cmi-verify-auth-otp-btn">Verify &amp; Log In</button>
            </div>
        </form>
        -->

    </div>
</div>
