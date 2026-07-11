<?php
// Public landing page — no auth required.
// Login button routes to the existing login.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NutriTrack — Nutritional Health Monitoring & Meal Management | CSWD Bacoor</title>
<link rel="icon" type="image/png" href="NUTRITRACK-LOGO.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/landing.css">
</head>
<body>

<!-- ============ NAV ============ -->
<nav class="nt-nav">
    <div class="nt-container nt-nav-row">
        <a href="#top" class="nt-brand">
            <img src="NUTRITRACK-LOGO.png" alt="NutriTrack logo">
            NutriTrack
        </a>

        <div class="nt-nav-links">
            <a href="#about">About</a>
            <a href="#features">Features</a>
            <a href="#roles">Who It's For</a>
            <a href="#faq">FAQ</a>
            <a href="#contact">Contact</a>
        </div>

        <div style="display:flex; align-items:center; gap:16px;">
            <a href="guardian/register.php" class="nt-register-btn">Register as Guardian</a>
            <a href="login.php" class="nt-login-btn">Log In</a>
            <button class="nt-menu-toggle" aria-label="Toggle menu" onclick="document.querySelector('.nt-nav-links').classList.toggle('nt-show')">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</nav>

<!-- ============ HERO ============ -->
<header class="nt-hero" id="top">
    <div class="nt-container nt-hero-grid">
        <div>
            <span class="nt-eyebrow">CSWD Bacoor · Child Development Centers</span>
            <h1>Every child's growth curve, <span>plotted before a problem is missed.</span></h1>
            <p>
                NutriTrack replaces paper masterlists and scattered spreadsheets with one
                centralized system for recording height, weight, and MUAC — so
                CSWD personnel, Child Development Workers, and guardians see the same
                nutritional status at the same time, computed against WHO Child
                Growth Standards.
            </p>
            <div class="nt-hero-actions">
                <a href="login.php" class="nt-cta-primary">Log in to NutriTrack</a>
                <a href="#how-it-works" class="nt-cta-secondary">See how it works</a>
            </div>
        </div>

        <div class="nt-curve-wrap">
            <div class="nt-curve-card">
                <div class="nt-curve-card-head">
                    <span class="nt-curve-card-title">Weight-for-Age Reference</span>
                    <span class="nt-curve-card-sub">WHO Growth Standard</span>
                </div>

                <svg class="nt-curve-svg" viewBox="0 0 420 220" xmlns="http://www.w3.org/2000/svg">
                    <!-- percentile band -->
                    <path class="nt-curve-band" d="M10,170 C90,150 150,60 200,50 C260,40 340,70 410,40 L410,140 C340,170 260,150 200,150 C150,150 90,190 10,210 Z" fill="#E4F0E3"></path>

                    <!-- gridlines -->
                    <line x1="10" y1="40" x2="410" y2="40" stroke="#DCE5D7" stroke-width="1"></line>
                    <line x1="10" y1="90" x2="410" y2="90" stroke="#DCE5D7" stroke-width="1"></line>
                    <line x1="10" y1="140" x2="410" y2="140" stroke="#DCE5D7" stroke-width="1"></line>
                    <line x1="10" y1="190" x2="410" y2="190" stroke="#DCE5D7" stroke-width="1"></line>

                    <!-- growth line -->
                    <path class="nt-curve-line" d="M10,190 C70,175 110,120 160,105 C210,90 250,95 290,75 C330,55 370,60 410,45"></path>

                    <!-- markers -->
                    <circle class="nt-curve-marker m1" cx="160" cy="105" r="6" fill="#2E7D32"></circle>
                    <circle class="nt-curve-marker m2" cx="290" cy="75" r="6" fill="#C1670A"></circle>
                    <circle class="nt-curve-marker m3" cx="410" cy="45" r="6" fill="#3B6FB6"></circle>
                </svg>

                <div class="nt-curve-legend">
                    <span class="nt-curve-legend-item"><span class="nt-curve-dot height"></span>Height-for-Age</span>
                    <span class="nt-curve-legend-item"><span class="nt-curve-dot weight"></span>Weight-for-Age</span>
                    <span class="nt-curve-legend-item"><span class="nt-curve-dot muac"></span>MUAC Status</span>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ============ ABOUT ============ -->
<section class="nt-section" id="about">
    <div class="nt-container nt-about-grid">
        <div>
            <div class="nt-section-eyebrow">Why NutriTrack exists</div>
            <h2 style="font-family:'Poppins',sans-serif; font-weight:700; font-size:28px; letter-spacing:-0.4px; margin-bottom:18px;">Manual monitoring can't catch a missed month in time</h2>
            <div class="nt-about-text">
                <p>
                    Under <strong>Operation Timbang Plus (OPT+)</strong>, Child Development
                    Centers across Bacoor weigh and measure every enrolled child, then
                    transcribe the results into masterlists and spreadsheets by hand
                    before CSWD can review them.
                </p>
                <p>
                    That hand-off is where delays start. A child who stops gaining weight
                    for two consecutive months should be flagged and referred — but if
                    the report is still sitting in a filing cabinet, that window closes
                    quietly. NutriTrack moves the whole record — profile, measurements,
                    feeding history, and status — onto one platform that computes WFA,
                    HFA, and WFL/H the moment data is entered.
                </p>
            </div>
        </div>

        <div class="nt-chip-stack">
            <div class="nt-chip">
                <span class="nt-chip-label">Report consolidation</span>
                <span class="nt-chip-before">Weeks of encoding</span>
                <span class="nt-chip-arrow">→</span>
                <span class="nt-chip-after">Generated on demand</span>
            </div>
            <div class="nt-chip">
                <span class="nt-chip-label">Nutritional status</span>
                <span class="nt-chip-before">Manually classified</span>
                <span class="nt-chip-arrow">→</span>
                <span class="nt-chip-after">Auto-computed per record</span>
            </div>
            <div class="nt-chip">
                <span class="nt-chip-label">Record access</span>
                <span class="nt-chip-before">One filing cabinet</span>
                <span class="nt-chip-arrow">→</span>
                <span class="nt-chip-after">Role-based, from any CDC</span>
            </div>
        </div>
    </div>
</section>

<!-- ============ FEATURES ============ -->
<section class="nt-section" id="features">
    <div class="nt-container">
        <div class="nt-section-head">
            <div class="nt-section-eyebrow">What's inside</div>
            <h2>One record, tracked at every checkpoint</h2>
            <p>Each module sits on the same child record, so a measurement taken at the CDC is the same one a guardian sees at home.</p>
        </div>

        <div class="nt-features-axis">
            <div class="nt-features-grid">
                <div class="nt-feature-card">
                    <span class="nt-feature-node"></span>
                    <div class="nt-feature-icon">📏</div>
                    <h3>Anthropometric Records</h3>
                    <p>Height, weight, and MUAC logged per visit, with full history per child.</p>
                </div>

                <div class="nt-feature-card">
                    <span class="nt-feature-node"></span>
                    <div class="nt-feature-icon">📊</div>
                    <h3>Automated Status Classification</h3>
                    <p>WFA, HFA, and WFL/H computed instantly against WHO Child Growth Standards.</p>
                </div>

                <div class="nt-feature-card">
                    <span class="nt-feature-node"></span>
                    <div class="nt-feature-icon">🍽️</div>
                    <h3>Feeding & Milk Program Tracking</h3>
                    <p>Attendance and food items served under the Supplementary Feeding Program, logged per session.</p>
                </div>

                <div class="nt-feature-card">
                    <span class="nt-feature-node"></span>
                    <div class="nt-feature-icon">🩺</div>
                    <h3>Health Info & Deworming</h3>
                    <p>Vaccination records, allergies, comorbidities, and deworming history in one profile.</p>
                </div>

                <div class="nt-feature-card">
                    <span class="nt-feature-node"></span>
                    <div class="nt-feature-icon">📄</div>
                    <h3>One-click Reports</h3>
                    <p>WMR, Masterlist, Feeding Attendance, and Terminal Reports generated straight from live data.</p>
                </div>

                <div class="nt-feature-card">
                    <span class="nt-feature-node"></span>
                    <div class="nt-feature-icon">🔐</div>
                    <h3>Role-Based Access</h3>
                    <p>CSWD, CDW, and Guardian accounts each see only what their role is authorized to view or edit.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ ROLES ============ -->
<section class="nt-section" id="roles">
    <div class="nt-container">
        <div class="nt-section-head">
            <div class="nt-section-eyebrow">Built for three roles</div>
            <h2>Everyone works from the same record</h2>
            <p>No re-encoding between offices — an update on one side of the system is visible to the others immediately.</p>
        </div>

        <div class="nt-roles-grid">
            <div class="nt-role-card cswd">
                <span class="nt-role-tag">CSWD Personnel</span>
                <h3>Oversight & Reports</h3>
                <ul>
                    <li>Manage CDCs, users, and access</li>
                    <li>Review at-risk children flagged by the system</li>
                    <li>Generate city-wide monitoring reports</li>
                    <li>Issue intervention guidance</li>
                </ul>
            </div>

            <div class="nt-role-card cdw">
                <span class="nt-role-tag">Child Development Workers</span>
                <h3>Frontline Data & Monitoring</h3>
                <ul>
                    <li>Record height, weight, and MUAC</li>
                    <li>Review guardian-submitted health information</li>
                    <li>Log feeding, milk, and deworming activity</li>
                    <li>Submit WMR and terminal reports</li>
                </ul>
            </div>

            <div class="nt-role-card guardian">
                <span class="nt-role-tag">Guardians</span>
                <h3>Stay Informed at Home</h3>
                <ul>
                    <li>View child's current nutritional status</li>
                    <li>Submit vaccination and medical records</li>
                    <li>Receive reminders and intervention guidance</li>
                    <li>Track monthly measurement history</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ============ GUARDIAN REGISTRATION ============ -->
<section class="nt-section" id="register">
    <div class="nt-container">
        <div class="nt-register-panel">
            <div class="nt-register-copy">
                <div class="nt-section-eyebrow">For Guardians</div>
                <h2>Getting your account only takes an access code</h2>
                <p>
                    Guardian accounts aren't self-signup from scratch — each one is
                    linked to a specific child's record, so a short verification step
                    keeps that link accurate.
                </p>
                <a href="guardian/register.php" class="nt-cta-primary" style="margin-top:6px;">Register as Guardian</a>
            </div>

            <ol class="nt-register-steps">
                <li>
                    <span class="nt-register-step-num">1</span>
                    <div>
                        <h4>Your child is enrolled at the CDC</h4>
                        <p>A Child Development Worker records your child's profile during enrollment.</p>
                    </div>
                </li>
                <li>
                    <span class="nt-register-step-num">2</span>
                    <div>
                        <h4>You receive an Access Code</h4>
                        <p>The system generates a unique code tied to your child's record. Ask your CDW for it.</p>
                    </div>
                </li>
                <li>
                    <span class="nt-register-step-num">3</span>
                    <div>
                        <h4>Create your guardian account</h4>
                        <p>Enter the access code on the registration page to link your account to your child's profile.</p>
                    </div>
                </li>
            </ol>
        </div>
    </div>
</section>

<!-- ============ HOW IT WORKS ============ -->
<section class="nt-section" id="how-it-works">
    <div class="nt-container">
        <div class="nt-section-head">
            <div class="nt-section-eyebrow">The flow</div>
            <h2>From enrollment to intervention</h2>
            <p>A real, ordered process — each step depends on data from the one before it.</p>
        </div>

        <div class="nt-steps">
            <div class="nt-step">
                <div class="nt-step-number">01</div>
                <h3>Enroll</h3>
                <p>Child and guardian details are recorded at the CDC and linked to a guardian account.</p>
            </div>

            <div class="nt-step">
                <div class="nt-step-number">02</div>
                <h3>Measure</h3>
                <p>CDW records height, weight, and MUAC during a scheduled visit.</p>
            </div>

            <div class="nt-step">
                <div class="nt-step-number">03</div>
                <h3>Classify</h3>
                <p>The system computes WFA, HFA, and WFL/H automatically against WHO standards.</p>
            </div>

            <div class="nt-step">
                <div class="nt-step-number">04</div>
                <h3>Act</h3>
                <p>At-risk children are flagged for intervention guidance and monitored month over month.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ FAQ ============ -->
<section class="nt-section" id="faq">
    <div class="nt-container">
        <div class="nt-section-head">
            <div class="nt-section-eyebrow">Before you log in</div>
            <h2>Frequently asked questions</h2>
            <p>Answers to what guardians and CDC staff ask most when they're new to NutriTrack.</p>
        </div>

        <div class="nt-faq-list">
            <details class="nt-faq-item" open>
                <summary>How do I get a guardian account?</summary>
                <p>Your child must first be enrolled at a Child Development Center. Your CDW will generate an access code tied to your child's record — use it on the <a href="guardian/register.php">registration page</a> to create your account.</p>
            </details>

            <details class="nt-faq-item">
                <summary>I lost my access code. What do I do?</summary>
                <p>Access codes are issued per child record, so they can't be self-recovered online. Coordinate with your child's Child Development Center or the CSWD Bacoor Nutrition Unit to have it reissued.</p>
            </details>

            <details class="nt-faq-item">
                <summary>How is my child's nutritional status determined?</summary>
                <p>Height, weight, and MUAC recorded by your CDW are compared against WHO Child Growth Standards. NutriTrack computes Weight-for-Age, Height-for-Age, and Weight-for-Length/Height automatically — no manual calculation involved.</p>
            </details>

            <details class="nt-faq-item">
                <summary>Why was my submitted health information rejected?</summary>
                <p>A CDW reviews every submission (vaccination record, allergies, comorbidities, medical history) before it becomes part of your child's official record. If something is unclear or incomplete, it's rejected with a stated reason, and you can update and resubmit right away.</p>
            </details>

            <details class="nt-faq-item">
                <summary>Can CDC staff use NutriTrack on a phone or tablet?</summary>
                <p>Yes. NutriTrack is a web-based system that works in a mobile browser, so measurements and feeding records can be entered on-site without a desktop computer.</p>
            </details>

            <details class="nt-faq-item">
                <summary>I forgot my password. How do I get back in?</summary>
                <p>Use the password reset option on the <a href="login.php">login page</a>. If you no longer have access to the contact details on file, reach out to your CDC or the CSWD Bacoor Nutrition Unit to have your account verified.</p>
            </details>
        </div>
    </div>
</section>

<!-- ============ FINAL CTA ============ -->
<section class="nt-section">
    <div class="nt-container">
        <div class="nt-final-cta">
            <h2>Already have an account with a Child Development Center?</h2>
            <a href="login.php" class="nt-final-cta-btn">Log in to NutriTrack</a>
        </div>
    </div>
</section>

<!-- ============ FOOTER ============ -->
<footer class="nt-footer" id="contact">
    <div class="nt-container">
        <div class="nt-footer-row">
            <div>
                <div class="nt-footer-brand">
                    <img src="NUTRITRACK-LOGO.png" alt="NutriTrack logo">
                    NutriTrack
                </div>
                <p class="nt-footer-address" style="margin-top:10px;">
                    City Social Welfare and Development Office of Bacoor<br>
                    Bacoor Boulevard, Brgy. Bayanan, City of Bacoor, Cavite
                </p>
            </div>

            <div>
                <p class="nt-footer-address">
                    For account access issues, please coordinate with your
                    assigned Child Development Center or the CSWD Bacoor
                    Nutrition Unit.
                </p>
            </div>
        </div>

        <div class="nt-footer-note">
            <span>© <?php echo date("Y"); ?> NutriTrack — Developed for CSWD Bacoor.</span>
            <span class="nt-footer-disclaimer">This system provides basic nutritional guidance only and does not replace professional medical or dietary advice from a licensed practitioner.</span>
        </div>
    </div>
</footer>

</body>
</html>