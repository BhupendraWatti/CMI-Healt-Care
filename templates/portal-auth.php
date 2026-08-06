<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="cmi-portal-auth" id="cmi-auth-container" data-type="<?php echo esc_attr( $type ); ?>" style="display: flex; justify-content: center; align-items: center; min-height: 480px; padding: 30px 15px;">
    <div class="cmi-cmi-card-auth" style="width: 100%; max-width: 440px; background: #ffffff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; padding: 35px 30px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

        <h2 style="font-size: 24px; font-weight: 500; color: #333333; margin: 0 0 20px 0; text-align: center;">
            <?php echo $type === 'partner' ? 'Partner Login / Register' : 'Login / Register'; ?>
        </h2>

        <!-- Auth Mode Tabs -->
        <div class="cmi-auth-toggle-tabs" role="tablist" aria-label="Login method" style="display: flex; gap: 8px; margin-bottom: 22px; background: #f3f4f6; padding: 4px; border-radius: 8px;">
            <button type="button" id="cmi-toggle-mobile-otp-btn" class="cmi-tab-btn active" role="tab" aria-selected="true" aria-controls="cmi-mobile-otp-form" style="flex: 1; padding: 10px 12px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; background: #ffffff; color: #00a99d; box-shadow: 0 2px 4px rgba(0,0,0,0.06); transition: all 0.2s;">
                📱 Login by OTP
            </button>
            <button type="button" id="cmi-toggle-email-btn" class="cmi-tab-btn" role="tab" aria-selected="false" aria-controls="cmi-email-login-form" style="flex: 1; padding: 10px 12px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; background: transparent; color: #6b7280; transition: all 0.2s;">
                ✉️ Email Login
            </button>
        </div>

        <div id="cmi-auth-msg" class="cmi-msg" role="status" aria-live="polite" style="display:none; margin-bottom: 20px;"></div>

        <!-- 1. Primary Auth: Airtel DLT 2-Step Mobile OTP Form -->
        <form id="cmi-mobile-otp-form" class="cmi-auth-form" method="post" role="tabpanel" aria-labelledby="cmi-toggle-mobile-otp-btn">
            <div id="cmi-otp-step-1">
                <div class="cmi-form-row" style="margin-bottom: 18px;">
                    <label for="cmi-otp-mobile" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Phone number</label>
                    <div class="cmi-phone-input-wrap" style="display: flex; border: 1px solid #cccccc; border-radius: 6px; overflow: hidden; height: 44px; background: #fff;">
                        <div style="background: #f3f4f6; border-right: 1px solid #cccccc; padding: 0 12px; display: flex; align-items: center; font-size: 14px; font-weight: 600; color: #4b5563;">
                            <span>🇮🇳 +91</span>
                        </div>
                        <input type="tel" id="cmi-otp-mobile" name="mobile" required placeholder="10-digit mobile number" maxlength="15" style="border: none; outline: none; padding: 10px 14px; font-size: 15px; width: 100%; box-shadow: none; background: transparent;" />
                    </div>
                </div>

                <div class="cmi-form-row" id="cmi-otp-name-row" style="margin-bottom: 18px;">
                    <label for="cmi-otp-name" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Full Name <small style="font-weight: normal; color: #6b7280;">(For new registration)</small></label>
                    <input type="text" id="cmi-otp-name" name="name" placeholder="Enter full name" style="width: 100%; border: 1px solid #cccccc; border-radius: 6px; padding: 10px 14px; font-size: 14px; outline: none; box-sizing: border-box;" />
                </div>

                <?php if ( $type === 'partner' ) : ?>
                    <div class="cmi-form-row" style="margin-bottom: 18px;">
                        <label for="cmi-reg-partner-type" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Partner Type</label>
                        <select id="cmi-reg-partner-type" style="width: 100%; border: 1px solid #cccccc; border-radius: 6px; padding: 10px 14px; font-size: 14px; outline: none; background: #fff; box-sizing: border-box;">
                            <option value="medical_partner">Medical Partner / Lab / Clinic</option>
                            <option value="cmi_doctor">Doctor</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div style="margin: 15px 0 22px 0; display: flex; align-items: flex-start; gap: 8px; font-size: 12px; color: #4b5563;">
                    <input type="checkbox" id="cmi-terms-check" checked required style="margin-top: 2px;" />
                    <label for="cmi-terms-check" style="font-size: 12px; color: #4b5563; font-weight: normal; margin: 0;">
                        By submitting, you agree to the <a href="#" style="color: #0073aa; text-decoration: none;">Terms and Privacy Policy</a>
                    </label>
                </div>

                <button type="button" id="cmi-send-auth-otp-btn" class="cmi-teal-btn" style="width: 100%; background: #00a99d; color: #ffffff; border: none; border-radius: 6px; height: 46px; font-size: 15px; font-weight: 600; cursor: pointer; margin-bottom: 12px; transition: background 0.2s;">
                    Send OTP via SMS
                </button>
            </div>

            <div id="cmi-otp-step-2" style="display:none;">
                <div class="cmi-form-row" style="margin-bottom: 18px;">
                    <label for="cmi-otp-code" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Enter 6-Digit Verification Code</label>
                    <input type="text" id="cmi-otp-code" placeholder="123456" maxlength="6" style="width: 100%; border: 2px solid #00a99d; border-radius: 6px; padding: 12px; font-size: 20px; letter-spacing: 6px; text-align: center; font-weight: 700; outline: none; box-sizing: border-box;" />
                </div>

                <button type="submit" id="cmi-verify-auth-otp-btn" class="cmi-teal-btn" style="width: 100%; background: #00a99d; color: #ffffff; border: none; border-radius: 6px; height: 46px; font-size: 15px; font-weight: 600; cursor: pointer; margin-bottom: 14px; transition: background 0.2s;">
                    Verify &amp; Log In
                </button>

                <div style="text-align: center; margin-top: 10px;">
                    <button type="button" id="cmi-resend-auth-otp-btn" style="background: transparent; border: none; color: #0073aa; font-size: 13px; cursor: pointer; text-decoration: underline;">
                        Resend OTP Code
                    </button>
                </div>
            </div>
        </form>

        <!-- 2. Email & Password Form -->
        <form id="cmi-email-login-form" class="cmi-auth-form" style="display:none;" method="post" role="tabpanel" aria-labelledby="cmi-toggle-email-btn">
            <div class="cmi-form-row" style="margin-bottom: 18px;">
                <label for="cmi-login-email" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Email address</label>
                <input type="email" id="cmi-login-email" placeholder="name@example.com" style="width: 100%; border: 1px solid #cccccc; border-radius: 6px; padding: 10px 14px; font-size: 14px; outline: none; box-sizing: border-box;" />
            </div>
            <div class="cmi-form-row" style="margin-bottom: 22px;">
                <label for="cmi-login-password" style="display: block; font-size: 14px; font-weight: 600; color: #333333; margin-bottom: 8px;">Password</label>
                <input type="password" id="cmi-login-password" placeholder="••••••••" style="width: 100%; border: 1px solid #cccccc; border-radius: 6px; padding: 10px 14px; font-size: 14px; outline: none; box-sizing: border-box;" />
            </div>
            <button type="submit" class="cmi-teal-btn cmi-auth-submit-btn" style="width: 100%; background: #00a99d; color: #ffffff; border: none; border-radius: 6px; height: 46px; font-size: 15px; font-weight: 600; cursor: pointer; margin-bottom: 12px;">
                Log In with Email
            </button>
            <button type="button" id="cmi-toggle-phone-btn" class="cmi-teal-btn" style="width: 100%; background: transparent; color: #00a99d; border: 2px solid #00a99d; border-radius: 6px; height: 46px; font-size: 14px; font-weight: 600; cursor: pointer;">
                ← Back to Phone OTP Login
            </button>
        </form>

    </div>
</div>
