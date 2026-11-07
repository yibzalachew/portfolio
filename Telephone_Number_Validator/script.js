document.getElementById("check-btn").addEventListener("click", function() {
    const userInput = document.getElementById("user-input").value.trim();
    const resultsDiv = document.getElementById("results-div");

    // If input is empty
    if (!userInput) {
      alert("Please provide a phone number");
      return;
    }

    const regex = /^(1\s?)?(\(\d{3}\)\s?|\d{3}[-\s]?)\d{3}[-\s]?\d{4}$/;
    const countryCodeRegex = /^1\s?/;

    // Check if the phone number matches the valid US format
    if (regex.test(userInput)) {
      if (countryCodeRegex.test(userInput) || !userInput.startsWith('1')) {
        resultsDiv.textContent = `Valid US number: ${userInput}`;
      } else {
        resultsDiv.textContent = `Invalid US number: ${userInput}`;
      }
    } else {
      resultsDiv.textContent = `Invalid US number: ${userInput}`;
    }
  });

  document.getElementById("clear-btn").addEventListener("click", function() {
    document.getElementById("results-div").textContent = '';
    document.getElementById("user-input").value = '';
  });
