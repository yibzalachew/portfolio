
// =========================================
// 1. MOBILE NAVIGATION
// =========================================

const menuToggle = document.getElementById('menu-toggle');
const navigation = document.getElementById('main-navigation');

if (menuToggle && navigation) {

    menuToggle.addEventListener('click', function () {

        navigation.classList.toggle('active');

        const isOpen = navigation.classList.contains('active');

        menuToggle.setAttribute('aria-expanded', isOpen);

        menuToggle.setAttribute(
            'aria-label',
            isOpen ? 'Close navigation menu' : 'Open navigation menu'
        );

        menuToggle.innerHTML = isOpen
            ? '<i class="fas fa-times"></i>'
            : '<i class="fas fa-bars"></i>';

    });


    // Close mobile menu after clicking a navigation link.

    navigation.querySelectorAll('a').forEach(link => {

        link.addEventListener('click', function () {

            navigation.classList.remove('active');

            menuToggle.setAttribute('aria-expanded', 'false');

            menuToggle.setAttribute(
                'aria-label',
                'Open navigation menu'
            );

            menuToggle.innerHTML = '<i class="fas fa-bars"></i>';

        });

    });


    // Close mobile menu when clicking outside.

    document.addEventListener('click', function (event) {

        if (
            !navigation.contains(event.target) &&
            !menuToggle.contains(event.target)
        ) {

            navigation.classList.remove('active');

            menuToggle.setAttribute('aria-expanded', 'false');

            menuToggle.setAttribute(
                'aria-label',
                'Open navigation menu'
            );

            menuToggle.innerHTML = '<i class="fas fa-bars"></i>';

        }

    });


    // Close mobile menu when screen becomes larger.

    window.addEventListener('resize', function () {

        if (window.innerWidth > 768) {

            navigation.classList.remove('active');

            menuToggle.setAttribute('aria-expanded', 'false');

            menuToggle.setAttribute(
                'aria-label',
                'Open navigation menu'
            );

            menuToggle.innerHTML = '<i class="fas fa-bars"></i>';

        }

    });

}


// =========================================
// 2. SMOOTH SCROLLING
// =========================================

document.querySelectorAll('a[href^="#"]').forEach(anchor => {

    anchor.addEventListener('click', function (event) {

        const targetId = this.getAttribute('href');

        if (!targetId || targetId === '#') {
            return;
        }

        const targetElement = document.querySelector(targetId);

        if (targetElement) {

            event.preventDefault();

            targetElement.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

        }

    });

});


// =========================================
// 3. ACTIVE NAVIGATION LINK
// =========================================

const sections = document.querySelectorAll(
    '#home, #about, #skills, #projects, #contact'
);

const navLinks = document.querySelectorAll(
    '.main-navigation .nav-link'
);

function updateActiveNavigation() {

    let currentSection = 'home';

    sections.forEach(section => {

        const sectionTop = section.offsetTop;

        if (window.scrollY >= sectionTop - 150) {

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

window.addEventListener('scroll', updateActiveNavigation);

window.addEventListener('load', updateActiveNavigation);


// =========================================
// 4. CURRENT YEAR IN FOOTER
// =========================================

const footerText = document.querySelector('.footer-container p');

if (footerText) {

    footerText.innerHTML =
        `&copy; ${new Date().getFullYear()} Yibzalachew Seifu. All Rights Reserved.`;

}
document.getElementById("current-year").textContent =
    new Date().getFullYear();
