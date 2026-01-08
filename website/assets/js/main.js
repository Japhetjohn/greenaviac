/**
 * Green AVIC - Main JavaScript
 * Clean and Simple
 */

// ==========================================
// ANIMATED COUNTER FOR STATS
// ==========================================
function animateCounter(element, target, duration = 2000) {
    let start = 0;
    const increment = target / (duration / 16); // 60fps
    const isPercentage = element.textContent.includes('%') || element.parentElement.querySelector('.stat-label');

    function updateCounter() {
        start += increment;

        if (start < target) {
            element.textContent = Math.floor(start) + (isPercentage ? '%' : '');
            requestAnimationFrame(updateCounter);
        } else {
            element.textContent = target + (isPercentage ? '%' : '');
        }
    }

    updateCounter();
}

// Initialize counters when they come into view
function initCounters() {
    const counters = document.querySelectorAll('.stat-number[data-target]');
    let hasAnimated = false;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !hasAnimated) {
                hasAnimated = true;

                // Animate all counters
                counters.forEach(counter => {
                    const target = parseInt(counter.getAttribute('data-target'));
                    animateCounter(counter, target);
                });
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -100px 0px'
    });

    // Observe the hero section
    const heroSection = document.querySelector('.hero');
    if (heroSection) {
        observer.observe(heroSection);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', initCounters);

// Mobile Menu Toggle
const navToggle = document.getElementById('navToggle');
const navList = document.querySelector('.nav-list');

if (navToggle) {
    navToggle.addEventListener('click', () => {
        navList.classList.toggle('active');
        navToggle.classList.toggle('active');
    });

    // Close menu when clicking on a link
    document.querySelectorAll('.nav-list a').forEach(link => {
        link.addEventListener('click', () => {
            navList.classList.remove('active');
            navToggle.classList.remove('active');
        });
    });

    // Close menu when clicking outside
    document.addEventListener('click', (e) => {
        if (!navToggle.contains(e.target) && !navList.contains(e.target)) {
            navList.classList.remove('active');
            navToggle.classList.remove('active');
        }
    });
}

// Smooth Scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href === '#' || href === '') return;

        e.preventDefault();
        const target = document.querySelector(href);
        if (target) {
            const offset = 70;
            const targetPosition = target.offsetTop - offset;
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        }
    });
});

// Newsletter Form
const newsletterForm = document.querySelector('.newsletter-form');
if (newsletterForm) {
    newsletterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const email = newsletterForm.querySelector('input[type="email"]').value;
        alert(`Thank you for subscribing with ${email}! We'll keep you updated.`);
        newsletterForm.reset();
    });
}

// Add scroll effect to header
const header = document.querySelector('.header');
let lastScroll = 0;

window.addEventListener('scroll', () => {
    const currentScroll = window.pageYOffset;

    if (currentScroll > 100) {
        header.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    } else {
        header.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
    }

    lastScroll = currentScroll;
});

// ==========================================
// SCROLL ANIMATIONS - Elements slide in!
// ==========================================
const scrollObserverOptions = {
    threshold: 0.15,
    rootMargin: '0px 0px -50px 0px'
};

const scrollObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('revealed');
        }
    });
}, scrollObserverOptions);

// Observe all animated elements
document.querySelectorAll('.scroll-reveal, .scroll-left, .scroll-right').forEach(element => {
    scrollObserver.observe(element);
});

// Observe feature cards and product cards
document.querySelectorAll('.feature-card, .product-card').forEach(card => {
    card.classList.add('scroll-reveal');
    scrollObserver.observe(card);
});

// Observe grid items
document.querySelectorAll('.grid-item').forEach((item, index) => {
    item.classList.add('scroll-reveal');
    item.style.transitionDelay = `${index * 0.1}s`;
    scrollObserver.observe(item);
});

console.log('%c Green AVIC ', 'background: #4CAF50; color: white; padding: 10px 20px; font-size: 14px; font-weight: bold;');
console.log('%c Redefining Hygiene, Elevating Women ', 'color: #4CAF50; font-size: 12px;');
