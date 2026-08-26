<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ask the Payments Assistant | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>
    <h3>Ask about what you owe</h3>

    <div class="field-row">
        <label for="studentNumber">Student number</label>
        <input type="text" id="studentNumber" placeholder="7-digit student number">
    </div>

    <div class="field-row">
        <label for="surname">Surname</label>
        <input type="text" id="surname" placeholder="As it appears on your enrolment">
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
            const surname = document.getElementById('surname').value.trim();
            const question = document.getElementById('question').value.trim();
            const chatBox = document.getElementById('chatBox');

            if (!/^\d{7}$/.test(studentNumber) || surname === '') {
                chatBox.innerHTML = '<div class="notice overdue">Enter your student number and surname.</div>';
                return;
            }

            chatBox.innerHTML = '<p class="small">Thinking…</p>';

            const res = await fetch('agent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_number: studentNumber, surname, question })
            });
            const data = await res.json();

            if (!data.success) {
                chatBox.innerHTML = `<div class="notice overdue">${String(data.error ?? '')
                    .replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c])}</div>`;
                return;
            }

            const esc = (t) => String(t ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[c]);

            let html = `<div class="notice">${esc(data.answer).replace(/\n/g, '<br>')}</div>`;

            if (data.outstanding && data.outstanding.length > 0) {
                html += '<table class="staff-table"><tr><th>Item</th><th>Amount</th><th>Due</th><th></th></tr>';
                data.outstanding.forEach(o => {
                    html += `<tr>
                        <td>${esc(o.item)}</td>
                        <td>$${Number(o.amount_aud).toFixed(2)} AUD</td>
                        <td>${esc(o.due_date || '-')}${o.overdue ? ' (overdue)' : ''}</td>
                        <td><a href="${esc(o.pay_link)}">Pay now</a></td>
                    </tr>`;
                });
                html += '</table>';
            }

            chatBox.innerHTML = html;
        });
    </script>
</body>
</html>
