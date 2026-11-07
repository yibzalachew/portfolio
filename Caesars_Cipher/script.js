// Smooth Scroll for Navigation Links
document.querySelectorAll('nav a').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelector(this.getAttribute('href')).scrollIntoView({
            behavior: 'smooth'
        });
    });
});

// Contact Link Alert Message
document.querySelectorAll('.contact-links a').forEach(link => {
    link.addEventListener('click', () => {
        alert("Redirecting to the contact platform!");
    });
});
