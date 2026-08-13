# Employee AI Assistant (Gemini)

Generic authenticated HR assistant for Khibrat HR employees, with optional **chat sessions + history**.

Flutter talks only to Laravel; Laravel talks to Gemini.

## Setup

```env
GEMINI_API_KEY=
# GEMINI_MODEL=gemini-3.5-flash
# GEMINI_TIMEOUT=30
# GEMINI_CHAT_HISTORY_LIMIT=20
```

Then: `php artisan migrate` and `php artisan config:clear`.

## Recommended Flutter flow (sessions)

1. `POST /api/employee/assistant/sessions` → receive `id` + welcome greeting message
2. `POST /api/employee/assistant/sessions/{id}/messages` with `{ "message": "..." }`
3. Display `data.answer` (also persisted as `assistant_message`)
4. `GET /api/employee/assistant/sessions/{id}` to reload history
5. `POST /api/employee/assistant/sessions` again for **محادثة جديدة** (new greeting)
6. Optional: `DELETE /api/employee/assistant/sessions/{id}`

## Legacy one-shot endpoint (still works)

`POST /api/employee/assistant/chat` — no history, no greeting, same `{ success, data.answer }` shape.

## Session endpoints

| Method | Path | Notes |
|--------|------|--------|
| POST | `/api/employee/assistant/sessions` | Creates session + Laravel welcome message |
| GET | `/api/employee/assistant/sessions` | Paginated list (own sessions only) |
| GET | `/api/employee/assistant/sessions/{session}` | Session + paginated messages |
| POST | `/api/employee/assistant/sessions/{session}/messages` | Send message (throttled) |
| DELETE | `/api/employee/assistant/sessions/{session}` | Delete chat only (not HR data) |

Auth: Sanctum. Roles: employee / hr_manager / department_manager / general_manager. Requires `employees` row. Rate limit on chat/message: `throttle:employee-assistant` (20/min/user).

### Send message response

```json
{
  "success": true,
  "data": {
    "answer": "بقي لديك 12 يوم إجازة.",
    "session_id": "...",
    "user_message": { "id": "...", "role": "user", "message": "...", "created_at": "..." },
    "assistant_message": { "id": "...", "role": "assistant", "message": "...", "created_at": "..." }
  }
}
```

## Architecture

```
Controller
  → EmployeeAssistantSessionService (sessions / history persistence)
  → EmployeeAssistantService (context + Gemini)
      → EmployeeAssistantContextBuilder (+ tagged providers)
      → GeminiService
```

- **Current authorized context = source of truth**
- **Chat history = conversational context only** (last `GEMINI_CHAT_HISTORY_LIMIT` messages)
- Greeting is created by Laravel on session create (once per session), not by Gemini every turn

## Security

- Session ownership: `user_id` + `company_id` from `auth()->user()`
- Never accept client `user_id` / `employee_id` / `company_id` to select private data
- All existing context providers unchanged and still employee-scoped

## Tests

```bash
php artisan test --filter=EmployeeAssistant
```
