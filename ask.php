<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ask the Payments Assistant — WSU Payments</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
    <h1>WSU Payments <span class="badge">x Interledger</span></h1>
    <h3>Ask about what you owe</h3>

    <div class="field-row">
        <label for="studentNumber">Student number</label>
        <input type="text" id="studentNumber" placeholder="7-digit student number">
    </div>

    <div class="field-row">
        <label for="question">Your question</label>
        <input type="text" id="question" placeholder="e.g. What do I owe and when is it due?" style="flex:1;">
        <button type="button" id="askBtn">Ask</button>
    </div>

    <div id="chatBox"></div>

    <script>
        document.getElementById('askBtn').addEventListener('click', async () => {
            const studentNumber = document.getElementById('studentNumber').value.trim();
            const question = document.getElementById('question').value.trim();
            const chatBox = document.getElementById('chatBox');

            if (!/^\d{7}$/.test(studentNumber)) {
                chatBox.innerHTML = '<div class="notice overdue">Enter a valid 7-digit student number.</div>';
                return;
            }

            chatBox.innerHTML = '<p class="small">Thinking…</p>';

            const res = await fetch('agent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_number: studentNumber, question })
            });
            const data = await res.json();

            if (!data.success) {
                chatBox.innerHTML = `<div class="notice overdue">${data.error}</div>`;
                return;
            }

            let html = `<div class="notice">${data.answer.replace(/\n/g, '<br>')}</div>`;

            if (data.outstanding && data.outstanding.length > 0) {
                html += '<table class="staff-table"><tr><th>Item</th><th>Amount</th><th>Due</th><th></th></tr>';
                data.outstanding.forEach(o => {
                    html += `<tr>
                        <td>${o.item}</td>
                        <td>$${o.amount_aud.toFixed(2)} AUD</td>
                        <td>${o.due_date || '—'}${o.overdue ? ' (overdue)' : ''}</td>
                        <td><a href="${o.pay_link}">Pay now</a></td>
                    </tr>`;
                });
                html += '</table>';
            }

            chatBox.innerHTML = html;
        });
    </script>
</body>
</html>
