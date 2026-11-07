
/* =========================================
   1. MOBILE NAVIGATION
========================================= */

const menuToggle = document.getElementById('menu-toggle');
const navigation = document.getElementById('main-navigation');

if (menuToggle && navigation) {

    // Open and close mobile navigation
    menuToggle.addEventListener('click', function (event) {

        event.stopPropagation();

        const isOpen = navigation.classList.toggle('active');

        menuToggle.setAttribute('aria-expanded', String(isOpen));

        menuToggle.setAttribute(
            'aria-label',
            isOpen ? 'Close navigation menu' : 'Open navigation menu'
        );

        menuToggle.innerHTML = isOpen
            ? '<i class="fas fa-times"></i>'
            : '<i class="fas fa-bars"></i>';

    });

    // Close menu after clicking a navigation link
    navigation.querySelectorAll('.nav-link').forEach(link => {

        link.addEventListener('click', function () {

            navigation.classList.remove('active');

            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-label', 'Open navigation menu');

            menuToggle.innerHTML = '<i class="fas fa-bars"></i>';

        });

    });

    // Close menu when clicking outside
    document.addEventListener('click', function (event) {

        if (
            !navigation.contains(event.target) &&
            !menuToggle.contains(event.target)
        ) {

            navigation.classList.remove('active');

            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-label', 'Open navigation menu');

            menuToggle.innerHTML = '<i class="fas fa-bars"></i>';

        }

    });

    // Close menu when switching to desktop screen size
    window.addEventListener('resize', function () {

        if (window.innerWidth > 768) {

            navigation.classList.remove('active');

            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-label', 'Open navigation menu');

            menuToggle.innerHTML = '<i class="fas fa-bars"></i>';

        }

    });

}


/* =========================================
   2. SMOOTH SCROLLING
========================================= */

// Smooth scrolling for navigation links
document.querySelectorAll('nav a').forEach(anchor => {

    anchor.addEventListener('click', function (event) {

        const targetId = this.getAttribute('href');

        if (targetId && targetId.startsWith('#')) {

            const targetSection = document.querySelector(targetId);

            if (targetSection) {

                event.preventDefault();

                targetSection.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });

            }

        }

    });

});


// Smooth scrolling for internal project links
document.querySelectorAll('.project-card a').forEach(anchor => {

    anchor.addEventListener('click', function (event) {

        const targetId = this.getAttribute('href');

        if (targetId && targetId.startsWith('#')) {

            const targetSection = document.querySelector(targetId);

            if (targetSection) {

                event.preventDefault();

                targetSection.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });

            }

        }

    });

});


/* =========================================
   3. ACTIVE NAVIGATION HIGHLIGHTING
========================================= */

const sections = document.querySelectorAll('main section');

const navLinks = document.querySelectorAll('.main-navigation .nav-link');

function updateActiveNavigation() {

    let currentSection = '';

    sections.forEach(section => {

        const sectionTop = section.offsetTop - 150;
        const sectionHeight = section.offsetHeight;

        if (
            window.scrollY >= sectionTop &&
            window.scrollY < sectionTop + sectionHeight
        ) {

            currentSection = section.getAttribute('id');

        }

    });

    navLinks.forEach(link => {

        link.classList.remove('active');

        if (link.getAttribute('href') === `#${currentSection}`) {

            link.classList.add('active');

        }

    });

}


// Update active navigation while scrolling
window.addEventListener('scroll', updateActiveNavigation);

// Set correct active link when page loads
window.addEventListener('load', updateActiveNavigation);


/* =========================================
   4. AUTOMATIC FOOTER YEAR
========================================= */

const currentYear = document.getElementById('current-year');

if (currentYear) {

    currentYear.textContent = new Date().getFullYear();

}
