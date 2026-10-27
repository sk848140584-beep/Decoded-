# Panel Shop — Railway setup

यह folder Telegram bot का Railway-ready Python port है। Uploaded v92 ZIP TeleBotHost के 243 command scripts का export था; वे Railway पर सीधे नहीं चलते। यह bundle core shop flows देता है: product catalog, wallet checkout, FamGateway direct UPI with automatic verification, API-generated products, और admin controls. सभी पुराने v92 features का one-to-one port इसमें शामिल नहीं है।

## Railway पर डालने वाली files

- `main.py`
- `requirements.txt`
- `Procfile`
- `railway.json`
- `.gitignore`
- `README_RAILWAY.md`

ZIP को extract करके ये files repository root में रखें। `main.py` का नाम न बदलें।

## Railway Variables

Deploy से पहले Railway → Variables में ये values जोड़ें:

```text
BOT_TOKEN=<BotFather से नया token>
BOT_USERNAME=<bot username, @ के बिना>
ADMIN_ID=<owner का Telegram numeric user ID>
SECOND_ADMIN_ID=0
ADMIN_CONTACT=@your_support_username
DATA_DIR=/data
DATA_ENCRYPTION_KEY=<नीचे दिए command से बनाई Fernet key>
```

`BOT_TOKEN`, `BOT_USERNAME`, `ADMIN_ID`, और `DATA_ENCRYPTION_KEY` required हैं। `SECOND_ADMIN_ID` और `ADMIN_CONTACT` optional हैं।

`DATA_ENCRYPTION_KEY` बनाने के लिए अपने computer के terminal में चलाएँ:

```bash
python -c "import base64,secrets; print(base64.urlsafe_b64encode(secrets.token_bytes(32)).decode())"
```

इस key को सिर्फ Railway Variables में रखें। इसे GitHub, bot chat या इस source file में न डालें। इसे सुरक्षित backup में रखें और database इस्तेमाल होने के बाद कभी न बदलें—API और payment credentials इसी key से encrypted हैं।

## Persistent database

Railway service में Volume जोड़ें, mount path `/data` रखें, और `DATA_DIR=/data` सेट करें। SQLite database और logs इसी path में लिखे जाते हैं। Volume नहीं होने पर Railway redeploy के बाद products, wallet balances और orders खो सकते हैं।

Start command `railway.json` और `Procfile` में `python main.py` है। Railway Python dependencies `requirements.txt` से install करेगा।

## Bot के अंदर setup

1. Bot को `/start` करें, फिर owner account से `/admin` खोलें।
2. FamGateway के लिए **FamPay Gateway Setup** खोलें। Base URL और API key दें। API key private chat में भेजें; bot उसे database में encrypt करता है। UPI/Gmail को FamGateway dashboard में configure करें।
3. External key API के लिए **Multi API Manager** में provider cURL/Python request का **redacted** example डालें और product को API slot assign करें। Live key cURL में न डालें; `{{api_key}}` placeholder इस्तेमाल करें। Slot key को Railway में `API_SLOT_1_KEY` से `API_SLOT_5_KEY` तक Variables में रखना बेहतर है, या admin bot को private message में देना (stored encrypted).
4. Admin panel से products, prices, API product IDs, support links और manual keys configure करें।

Checkout में Wallet Balance और Direct UPI विकल्प हैं। FamGateway payment का background auto-verification चलता है; payment screen में manual **Verify Payment** fallback भी रहता है। Product key सफल payment के बाद ही deliver होती है।

API request format और response shape provider के अनुसार अलग हो सकते हैं। API endpoint, method, required fields, product ID, duration, और success response में key वाला field सही configure करें। Provider के credentials या actual API call को deploy से पहले live verify नहीं किया गया है।

## Security

- पहले upload किए गए source/archive में exposed-looking Telegram/payment credentials और host export token थे। संबंधित providers से उन्हें rotate/revoke करें। पुराना ZIP GitHub/Railway पर upload न करें।
- यह Railway bundle uploaded TeleBotHost archive, `security.json`, और integration setup text को शामिल नहीं करता।
- Bot token को @BotFather से regenerate करें; पुराना exposed token उपयोग न करें।
- `DATA_ENCRYPTION_KEY` खोने पर encrypted API/payment settings पढ़ी नहीं जा सकेंगी। Database और key का अलग-अलग सुरक्षित backup रखें।
