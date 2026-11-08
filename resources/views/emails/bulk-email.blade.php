<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? '' }}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;padding:16px;">
        <tr>
            <td align="left" style="font-size:15px;line-height:1.6;color:#111827;">
                {!! $bodyHtml !!}
            </td>
        </tr>
    </table>
</body>
</html>
