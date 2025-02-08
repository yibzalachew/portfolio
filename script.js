document.querySelectorAll('nav a').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        
        if (this.getAttribute('href').startsWith('#')) {
            e.preventDefault(); 
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        }
    });
});

document.querySelectorAll('.project-card a').forEach(anchor => {
    anchor.addEventListener('click', function (e) {

        if (this.getAttribute('href').startsWith('#')) {
            e.preventDefault(); 
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        }
    });
});
