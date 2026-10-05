# Integration

## Studio frontend

Give the studio team the deployed URL:

```
https://<service>/studio.html
```

That is the complete studio frontend: sign in, inbox of order threads (unread first), open an order, download the
customer's files, reply with text and files, mark done/reopen, assign to a designer (admins), manage the team
(admins). A link like `https://<service>/studio.html#order=000001234` opens that order directly (the webhook
notifications use it).

**Building your own studio frontend instead?** Call the JSON API on the same service and add your site's origin
to `STUDIO_ALLOWED_ORIGINS`:

| Method + path | Body | Returns |
|---|---|---|
| `POST /studio/api/login` | `{"email","password"}` | `{"token","expires_in","user"}`. Send `Authorization: Bearer <token>` afterwards |
| `POST /studio/api/logout` | | `{"ok":true}` |
| `GET /studio/api/me` | | `{"user":{id,name,email,role}}` |
| `GET /studio/api/threads?status=open\|closed\|all` | | `{"threads":[{order_number,customer_name,customer_email,status,assigned_to,assigned_name,unread,last_sender,last_message_at}],"unread_total"}` |
| `GET /studio/api/threads/{order}` | | `{"thread":{…},"messages":[{id,from:"customer"\|"studio",name,text,at,attachments:[{id,name,size}]}]}` and marks it read |
| `POST /studio/api/threads/{order}/messages` | multipart: `text`, `files[]` | `{"message":{…}}` |
| `POST /studio/api/threads/{order}/status` | `{"status":"open"\|"closed"}` | `{"thread":{…}}` |
| `POST /studio/api/threads/{order}/assign` | `{"user_id": 3}` or `{"user_id": null}` | `{"thread":{…}}` (admins) |
| `GET /studio/api/users` | | `{"users":[{id,name,email,role}]}` (admins) |
| `POST /studio/api/users` | `{"email","name","role":"admin"\|"designer","password"}` | `{"users":[…]}` (admins) |
| `POST /studio/api/users/{id}/deactivate` | | `{"users":[…]}` (admins) |
| `GET /studio/api/attachments/{id}` | | the file (download) |

Errors are `{"error":"code","message":"text you can show"}` with a matching HTTP status
(400, 401 sign in again, 403, 404, 409, 413, 429).

## Pro frontend

Add once (any page, before `</body>`). `data-token-key` is where Pro keeps the Magento customer token, the same
value as for the chatbot widget:

```html
<script src="https://<service>/order-messages.js" data-token-key="customerToken"></script>
```

Then, for example on the order detail page or in the order list:

```html
<button onclick="BitenxtOrderMessages.open('000001234')">Message the studio / upload files</button>
```

```js
BitenxtOrderMessages.open(orderNumber)            // panel: thread + text + file upload
BitenxtOrderMessages.unread()                     // → {total, orders:[{order_number, unread, last_message_at}]}
BitenxtOrderMessages.setToken(token)              // if the token is in app state, not storage
```

Add the Pro origin (e.g. `https://uat-pro.bitenxt.com`) to `ALLOWED_ORIGINS`.

## Support chatbot

The chatbot already has the customer's Magento token, so it can use the same customer API, from the browser
widget or from its PHP backend:

| Method + path | Body | Returns |
|---|---|---|
| `GET /api/orders/{order}/messages` | | `{"order_number","messages":[{id,from:"you"\|"studio",name,text,at,attachments}]}` and marks studio replies read |
| `POST /api/orders/{order}/messages` | multipart `text` + `files[]`, or JSON `{"text"}` | `201 {"order_number","message"}` and notifies the studio |
| `GET /api/unread` | | `{"total","orders":[…]}` |
| `GET /api/attachments/{id}` | | the file |

All with `Authorization: Bearer <customer's Magento token>`. Short order numbers (`1234`) are matched to the full
number (`000001234`), as in the chatbot.

Typical flows:

- **Customer says "I want to send new scans for order 1234"** → the widget loads `order-messages.js` and calls
  `BitenxtOrderMessages.open('1234')`, or shows its own file picker and calls
  `BitenxtOrderMessages.send('1234', 'Updated scans', fileInput.files)`.
- **"Did the studio reply?"** → `GET /api/unread` or `GET /api/orders/{order}/messages`, and summarise.
- **Unread badge** on the chat bubble → `BitenxtOrderMessages.unread().then(r => r.total)`.
