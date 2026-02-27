<?php
$pageTitle = 'iSchool | Contact';
$activePage = 'contact';
$submitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;
}
require_once 'includes/landing-header.php';
?>

<main>
    <section class="container hero">
        <div data-reveal>
            <h1>Let us talk about your school.</h1>
            <p>Tell us about your needs and we will tailor a rollout plan that fits your team.</p>
        </div>
    </section>

    <section class="container" data-reveal>
        <div class="story-wrap">
            <div class="story-panel">
                <h2 class="section-title">Contact form</h2>
                <?php if ($submitted): ?>
                    <p>Thanks for reaching out. A member of our team will contact you shortly.</p>
                <?php endif; ?>
                <form method="POST" class="contact-form">
                    <label>
                        School name
                        <input type="text" name="school_name" required>
                    </label>
                    <label>
                        Your name
                        <input type="text" name="full_name" required>
                    </label>
                    <label>
                        Work email
                        <input type="email" name="email" required>
                    </label>
                    <label>
                        Role
                        <input type="text" name="role" placeholder="Principal, Teacher, Proprietor">
                    </label>
                    <label>
                        Message
                        <textarea name="message" rows="4" placeholder="Tell us about your school"></textarea>
                    </label>
                    <button class="btn btn-primary" type="submit">Send request</button>
                </form>
            </div>
            <aside class="proof-panel">
                <h3>Reach us directly</h3>
                <p><strong>Email:</strong> support@ischool.app</p>
                <p><strong>Phone:</strong> +234 800 000 0000</p>
                <p><strong>Office:</strong> 12 Learning Way, Lagos</p>
                <div class="mt-4">
                    <p><strong>Hours:</strong> Mon - Fri, 9:00am - 6:00pm</p>
                </div>
            </aside>
            <div class="story-panel mt-4">
                <h3>Follow us</h3>
                <p>Join our community for product updates and school success stories.</p>
                <div class="mt-3 flex flex-col gap-2">
                    <a class="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="#" aria-label="iSchool on Facebook">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M13 9h3V6h-3c-2.2 0-4 1.8-4 4v2H7v3h2v6h3v-6h3l1-3h-4v-2c0-.6.4-1 1-1z"/>
                        </svg>
                        Facebook
                    </a>
                    <a class="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="#" aria-label="iSchool on Instagram">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4zm10 2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm-5 3.5A3.5 3.5 0 1 1 8.5 12 3.5 3.5 0 0 1 12 8.5zm0 2A1.5 1.5 0 1 0 13.5 12 1.5 1.5 0 0 0 12 10.5zm4.5-3a1 1 0 1 1-1 1 1 1 0 0 1 1-1z"/>
                        </svg>
                        Instagram
                    </a>
                    <a class="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="#" aria-label="iSchool on X">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M18.9 3H21l-6.5 7.4L21.5 21h-4.2l-4.9-7-6.1 7H3.9l6.9-7.9L2.5 3h4.3l4.5 6.4L18.9 3zm-2.1 15h1.7L7.4 6H5.6l11.2 12z"/>
                        </svg>
                        X
                    </a>
                    <a class="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="#" aria-label="iSchool on LinkedIn">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 8.98h3.96V21H3V8.98zM9.5 8.98h3.8v1.64h.05c.53-1 1.83-2.05 3.77-2.05 4.03 0 4.78 2.65 4.78 6.1V21h-3.96v-5.31c0-1.27-.02-2.9-1.77-2.9-1.77 0-2.04 1.38-2.04 2.8V21H9.5V8.98z"/>
                        </svg>
                        LinkedIn
                    </a>
                    <a class="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="#" aria-label="iSchool on WhatsApp">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 4a8 8 0 0 0-6.9 12.1L4 20l4-1.1A8 8 0 1 0 12 4zm4.6 11.3c-.2.6-1 1-1.6 1.1-.4.1-.8.2-1.8-.1-1.6-.5-2.6-1.1-4.1-2.9-1.4-1.7-2.3-3.4-2.4-4.2-.1-.8.3-1.5.8-1.8.3-.2.7-.2 1-.2.2 0 .4 0 .6.5l.7 1.7c.1.3.1.5 0 .7-.1.2-.2.3-.4.5-.2.2-.3.3-.1.7.2.4.8 1.4 1.8 2.3 1.2 1.1 2.2 1.4 2.6 1.6.3.1.6.1.8-.1.2-.2.4-.5.6-.7.2-.2.4-.3.7-.2l1.7.8c.3.1.5.2.6.3.1.2.1.6 0 1z"/>
                        </svg>
                        WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </section>

    <section class="container" data-reveal>
        <div class="cta-band">
            <div>
                <h2 class="section-title">Need a quick demo?</h2>
                <p>We can schedule a walkthrough within 24 hours.</p>
            </div>
            <a class="btn btn-primary" href="pricing.php">View Pricing</a>
        </div>
    </section>
</main>

<?php require_once 'includes/landing-footer.php'; ?>


