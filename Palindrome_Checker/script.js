document.getElementById("check-btn").addEventListener("click", function() {
    const inputText = document.getElementById("text-input").value;

    if (inputText.trim() === "") {
        alert("Please input a value");
        return;
    }

    const cleanedText = inputText.replace(/[^a-zA-Z0-9]/g, '').toLowerCase();

   
    const isPalindrome = cleanedText === cleanedText.split('').reverse().join('');

    if (isPalindrome) {
        document.getElementById("result").textContent = `${inputText} is a palindrome`;
    } else {
        document.getElementById("result").textContent = `${inputText} is not a palindrome`;
    }
});