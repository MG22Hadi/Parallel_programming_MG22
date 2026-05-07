const tokens = [
    '7|lA6Z3rRZ772amZWOlqFCgyKXpzShAT4Rz45RvLnza59d29f5',
    '8|sxtGNOyjYiUeAQDjWmXc9AyXlVrPy6gUiK7aOPUhae097c4c',
    '9|uLxe0bbTjfVNmiOWW10HH2IgUf41BMKaGbvWB1epfccc0ced'
];

const url = 'http://127.0.0.1:8000/api/checkout';

async function checkout(token) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            }
        });
        const data = await response.json();
        console.log(`Token ${token}: ${response.status} - ${JSON.stringify(data)}`);
    } catch (error) {
        console.error(`Token ${token}: Error - ${error.message}`);
    }
}

async function runConcurrentCheckouts() {
    const promises = tokens.map(token => checkout(token));
    await Promise.all(promises);
}

runConcurrentCheckouts();