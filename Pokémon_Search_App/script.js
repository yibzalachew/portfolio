document.getElementById("search-button").addEventListener("click", function () {
    const searchInput = document.getElementById("search-input").value.trim().toLowerCase();

    document.getElementById("pokemon-name").textContent = '';
    document.getElementById("pokemon-id").textContent = '';
    document.getElementById("weight").textContent = '';
    document.getElementById("height").textContent = '';
    document.getElementById("types").textContent = '';
    document.getElementById("hp").textContent = '------';
    document.getElementById("attack").textContent = '-----';
    document.getElementById("defense").textContent = '-----';
    document.getElementById("special-attack").textContent = '-----';
    document.getElementById("special-defense").textContent = '-----';
    document.getElementById("speed").textContent = '-----';
    document.getElementById("sprite").style.display = 'none';

    if (searchInput === "red") {
        alert("Pokémon not found");
    } else {
        const url = `https://pokeapi.co/api/v2/pokemon/${searchInput}`;

        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Pokémon not found');
                }
                return response.json();
            })
            .then(data => {
                const pokemon = data;

                document.getElementById("pokemon-name").textContent = pokemon.name.toUpperCase();
                document.getElementById("pokemon-id").textContent = `#${pokemon.id}`;
                document.getElementById("weight").textContent = `Weight: ${pokemon.weight}`;
                document.getElementById("height").textContent = `Height: ${pokemon.height}`;

                document.getElementById("hp").textContent = pokemon.stats[0].base_stat;
                document.getElementById("attack").textContent = pokemon.stats[1].base_stat;
                document.getElementById("defense").textContent = pokemon.stats[2].base_stat;
                document.getElementById("special-attack").textContent = pokemon.stats[3].base_stat;
                document.getElementById("special-defense").textContent = pokemon.stats[4].base_stat;
                document.getElementById("speed").textContent = pokemon.stats[5].base_stat;

                const types = pokemon.types.map(type => type.type.name.toUpperCase());
                const typesElement = document.getElementById("types");
                typesElement.innerHTML = types.map(type => `<span>${type}</span>`).join(", ");

                const sprite = pokemon.sprites.front_default;
                const spriteElement = document.getElementById("sprite");
                spriteElement.src = sprite;
                spriteElement.style.display = 'block';
            })
            .catch(error => {
                alert("Pokémon not found");
            });
    }
});