# Chat-Architektur — was wird gespeichert, gestreamt, ans LLM geschickt?

Dieses Dokument beschreibt die typo3-agent Chat-Pipeline aus
Entwickler-Sicht: Datenmodell, Message-Schema, die drei
Repräsentationen einer Konversation (DB, LLM, Client-Stream) und den
zeitlichen Ablauf von „neuer Chat" bis „Antwort fertig".

---

## 1. Datenmodell — was lebt in der DB?

Eine Konversation = **ein** Datensatz in `tx_agent_task`. Die
wichtigsten Felder:

| Spalte           | Typ   | Inhalt |
|------------------|-------|--------|
| `prompt`         | text  | Der ursprüngliche User-Text aus dem „Neuer Chat"-Formular (nur Eingabe-Snapshot — wird **nicht** wieder gelesen, sobald `messages` befüllt ist) |
| `messages`       | json  | Die komplette Konversation als JSON-Array. `NULL` bis der Agent zum ersten Mal läuft. |
| `status`         | int   | `0` Pending, `1` InProgress, `2` Ended, `3` Failed |
| `result`         | text  | Finaler Assistant-Text (für Listenansicht) |
| `cruser_id`      | int   | BE-User, dem der Task gehört |
| `workspace_id`   | int   | Workspace, in dem der Task läuft |
| `context_table` / `context_uid` | varchar/int | Datensatz, in dessen Kontext der Chat gestartet wurde |
| `return_url`     | text  | Backlink zur Ursprungsmaske |

Daneben `tx_agent_task_change` — Audit-Trail für Workspace-Mutationen
pro Task (für die „Geänderte Records"-Anzeige in der Show-View).

---

## 2. Das Message-Schema (innerhalb der JSON-Spalte `messages`)

Ein Eintrag ist ein assoziatives Array. Felder je nach `role`:

```
{
  role: 'system' | 'user' | 'assistant' | 'tool',

  // gemeinsam
  content?: string,

  // nur user
  attachments?: [
    { uid?: int, identifier?: string, name: string,
      mime_type?: string, unresolvable?: bool }
  ],

  // nur assistant
  tool_calls?: [
    { id: string, type: 'function',
      function: { name: string, arguments: string /* JSON */ } }
  ],

  // nur tool
  tool_call_id?: string,
  _media?: [
    { mime: string, data: string /* base64 */, filename?: string }
  ]
}
```

Wichtig: `_media` ist ein **interner** Schlüssel. Er wird gespeichert
(damit Wiederaufnahme funktioniert) und beim Senden an das LLM in einen
echten Multimodal-Block übersetzt — siehe Abschnitt 3b.

---

## 3. Drei Sichten derselben Konversation

Das ist der zentrale Trick, der einem leicht den Überblick raubt: **die
Messages existieren in drei Repräsentationen.** Sie heißen alle
„messages", sehen aber unterschiedlich aus.

```
                ┌─────────────────────────────────┐
                │   tx_agent_task.messages (DB)   │   ◄── canonical, full fidelity
                │   strukturierte Felder:         │
                │   attachments[], tool_calls[],  │
                │   _media[], tool_call_id        │
                └─────────────────────────────────┘
                         │              │
            ┌────────────┘              └───────────┐
            ▼                                       ▼
┌───────────────────────────┐         ┌─────────────────────────────────┐
│  serializeForLlm()        │         │  SSE-Events (Client)            │
│  ─ verformt für OpenRouter│         │  ─ inkrementell, Event-basiert  │
│                           │         │                                 │
│  attachments → Marker-    │         │  llm_start, content_delta,      │
│    Block am Content-Ende  │         │  tool_call_delta,               │
│  _media   → Follow-Up     │         │  assistant_message, tool_start, │
│    user-Message als       │         │  tool_result, user_message,     │
│    image_url/file-Block   │         │  change_tracked, done, error    │
└───────────────────────────┘         └─────────────────────────────────┘
            ▼                                       ▼
        OpenRouter                              Browser
        (Anthropic /                            (chat-element.ts
         OpenAI compat)                          handleSseEvent)
```

### 3a. DB-Sicht (kanonisch)

Was im Feld `messages` steht, ist die Quelle der Wahrheit. Nach jedem
LLM-Turn und nach jedem Tool-Call wird der ganze Stack via
`AgentTaskRepository::saveState()` neu geschrieben. Das ist auch der
Stand, der nach einem Reload der Show-View dem Frontend übergeben wird.

Anhänge stehen als **Refs** (`uid`/`identifier`/`name`/`mime_type`)
drin — **niemals** die Datei-Bytes selbst.

### 3b. LLM-Sicht (`AgentService::serializeForLlm`)

Das LLM braucht ein leicht anderes Format, weil OpenRouter strikte
Annahmen zu Multimodal-Content und Tool-Sequenzen hat. Zwei
Transformationen:

1. **User-Anhänge → Text-Marker.**
   Der `attachments[]`-Block wird entfernt und durch einen Text-Anhang
   im `content` ersetzt:
   ```
   ---
   Angehängte Dateien (Inhalt via ReadFile abrufbar):
   sys_file:42 — fileadmin/x.png (image/png)
   ```
   Das LLM sieht die Dateien also als Hinweis. Wenn es den Inhalt
   tatsächlich braucht, muss es das `ReadFile`-Tool aufrufen. Damit ist
   der Datei-Zugriff einheitlich (immer über ein Tool) und große
   User-Messages bleiben klein.

2. **Tool-`_media` → eigene Follow-Up-User-Message.**
   Wenn `ReadFile` auf eine Bilddatei zugreift, liefert das Tool zwei
   Dinge: eine Text-Repräsentation (für `content`) **und** rohe Bytes
   (in `_media`). Die Bytes können wegen Provider-Einschränkungen
   nicht in der `tool`-Message selbst transportiert werden:

   > OpenAI/OpenRouter verlangen, dass alle `tool`-Messages
   > unmittelbar hinter ihrer Owner-`assistant`-Message stehen.
   > Etwas Nicht-Tool dazwischenzuschieben kippt die Sequenz.

   Lösung: alle Media-Blöcke einer Tool-Call-Batch werden im
   `serializeForLlm` gepuffert und **direkt nach** dem letzten
   Tool-Result der Batch als eigene `user`-Message mit
   Multimodal-Content-Array ausgegeben:
   ```json
   { "role": "user",
     "content": [
       {"type":"text","text":"Inhalt der über ReadFile abgerufenen Datei(en):"},
       {"type":"image_url", "image_url": {"url":"data:image/png;base64,..."}}
     ] }
   ```

Diese serialisierte Form ist **transient** — sie wird nur am Call-Site
des LLM-Aufrufs gebaut und nie persistiert.

### 3c. Client-Sicht (SSE-Stream)

Der Browser bekommt die Konversation nicht als Snapshot, sondern als
Strom von Events. Jeder Event entspricht einer Zustandsänderung im
Agenten. Liste aller Event-Typen (siehe `chat-element.ts`
`handleSseEvent`):

| Event              | Wann                                                                 | Payload (relevante Felder) |
|--------------------|----------------------------------------------------------------------|----------------------------|
| `llm_start`        | Vor jedem LLM-Call innerhalb einer Iteration                         | iteration |
| `content_delta`    | Pro LLM-Stream-Chunk, sobald Text fließt                             | text |
| `tool_call_delta`  | Pro LLM-Stream-Chunk, der einen Tool-Call enthält                    | (provider-spezifisch) |
| `assistant_message`| Wenn LLM-Antwort fertig ist (eine pro Iteration). Bei fresh task auch synthetisch mit iteration=-1 für die Pre-Loaded-Context-Tools | message |
| `tool_start`       | Bevor ein Tool-Call ausgeführt wird (inkl. synthetische GetPage/ReadTable) | tool_call_id, tool_name, arguments |
| `tool_result`      | Nachdem ein Tool zurückgekehrt ist                                   | tool_call_id, content |
| `user_message`     | **Nur beim ersten Lauf**: nachdem `buildMessages` die synthetischen Kontext-Calls emittiert hat, materialisiert dieses Event die User-Prompt-Message (damit die Reihenfolge im UI stimmt) | message |
| `change_tracked`   | Wenn ein Tool einen Workspace-Mutationsdatensatz erzeugt             | tablename, record_uid, … |
| `done`             | Loop ist beendet                                                     | status, messages (final) |
| `error`            | Fehler                                                               | error, status |

Der Client baut aus diesen Events seinen eigenen lokalen Message-Stack
auf, **außer** beim `done`-Event: dort wird der lokale Stack durch das
authoritative `messages`-Array aus dem Payload ersetzt. Damit ist
sichergestellt, dass nach Auflage des Streams die Browser-Sicht exakt
der DB-Sicht entspricht.

---

## 4. Lebenszyklus einer Konversation

Der zeitliche Ablauf, von „User klickt Neu" bis „Antwort fertig":

```
USER (Browser)              HTTP / SSE                SERVER                                LLM
══════════════              ══════════                ══════                                ═══

(1) POST prompt+attachments ──────────────────►  ChatController::newAction
    /chat/new                                    │
    body: {message,table,uid,                    │   attachmentRefs = AgentService::resolveAttachmentRefs(raw)
           attachments:[json],…}                 │   initial = AgentService::buildInitialMessages(pid, ctxTable, ctxUid, prompt, attachmentRefs)
                                                 │     ├ GetPage tool (live ausgeführt)
                                                 │     ├ ReadTable tool
                                                 │     └ returns
                                                 │       [ {system},
                                                 │         {assistant, content:"Ich lade…", tool_calls:[GetPage,ReadTable]},
                                                 │         {tool, GetPage result},
                                                 │         {tool, ReadTable result},
                                                 │         {user, content: prompt, attachments?:[…]} ]
                                                 │
                                                 │   AgentTaskRepository::insert
                                                 │     tx_agent_task.prompt    = "…"
                                                 │     tx_agent_task.messages  = JSON(initial)
                                                 │     tx_agent_task.status    = Pending
                                                 │
                                                 ▼
                                              302 → /chat/show?task=N
                       ◄──────────────────────


(2) GET /chat/show     ─────────────────────►   ChatController::showAction
                                                 │
                                                 │   messages = decode(task.messages)
                                                 │   isNewTask = task.status == Pending → auto-start=1
                                                 │   → initial-messages an Chat-Element bleibt LEER,
                                                 │     damit doAutoStart sie via SSE live nachzeichnet
                                                 │
                                                 ▼
                       ◄── HTML mit  <hn-agent-chat
                                       auto-start="1"
                                       initial-messages="[]"
                                       send-uri="..." stream-uri="..." …>


(3) JS firstUpdated()
    doAutoStart()
    POST /chat/stream  ═════════════════════►   ChatController::streamMessageAction
    body: {message:""}                           │
    (empty)                                      │   isInitialProcessing = task.status == Pending
                                                 │                          && body empty
                                                 │   ── AgentService::processTask ──
                                                 │   │
                                                 │   ├ isFresh = task.status == Pending
                                                 │   ├ claim() → status=InProgress
                                                 │   ├ setupBackendUserContext()
                                                 │   ├ messages = decode(task.messages)
                                                 │   │   (kein erneuter GetPage/ReadTable —
                                                 │   │    der Stand stammt aus newAction)
                                                 │   │
                                                 │   ├ emitInitialContextEvents()
       SSE event ◄──────────────────────────  │   │   assistant_message  (iter=-1)
       SSE event ◄──────────────────────────  │   │   tool_start  (GetPage)
       SSE event ◄──────────────────────────  │   │   tool_result (GetPage)
       SSE event ◄──────────────────────────  │   │   tool_start  (ReadTable)
       SSE event ◄──────────────────────────  │   │   tool_result (ReadTable)
       SSE event ◄──────────────────────────  │   │   user_message (= prompt)
                                                 │   │
                                                 │   └ runLoop():
                                                 │       LOOP iteration:
                                                 │         msgsForLlm = serializeForLlm(messages)
                                                 │              │  (attachments → marker,
                                                 │              │   _media   → follow-up user msg)
                                                 │              ▼
                                                 │         llmService::chatCompletionStream ════════════►  OpenRouter
       SSE event ◄────  llm_start              │              │                                          (streaming)
       SSE event ◄────  content_delta (x N)    │              ◄────── tokens ───────────────────────────
       SSE event ◄────  tool_call_delta (x M)  │              │
                                                 │         messages[] ← {assistant, content/tool_calls}
                                                 │         repo.saveState(messages, InProgress)   ◄── PERSIST
       SSE event ◄────  assistant_message       │
                                                 │         IF no tool_calls → break LOOP
                                                 │         FOR each tool_call:
       SSE event ◄────  tool_start              │           result = executeToolCall(name, args)
                                                 │           messages[] ← {tool, content, _media?}
       SSE event ◄────  tool_result             │
                                                 │           trackChange(taskUid, result)
       SSE event ◄────  change_tracked          │
                                                 │         repo.saveState(messages, InProgress)   ◄── PERSIST
                                                 │       END LOOP
                                                 │       result = lastAssistant.content
                                                 │       repo.saveState(messages, Ended, result) ◄── PERSIST
       SSE event ◄────  done {messages: [...]}   ─────────────────────────────────────────
       (client replaces local stack with payload)


(4) User schreibt Folge-Nachricht
    POST /chat/stream  ═════════════════════►   ChatController::streamMessageAction
    body: {message, attachments: "[json]"}       │   isInitialProcessing = false (status != Pending)
                                                 │   ── AgentService::continueChat ──
                                                 │   │   attachmentRefs = resolveAttachmentRefs(raw)
                                                 │   │   messages = decode(task.messages) ?? []
                                                 │   │   messages[] ← {user, content, attachments}
                                                 │   │   repo.saveState(messages, Pending)    ◄── PERSIST
                                                 │   │   claim()
                                                 │   └   runLoop()   // identisch zu (3)
                                                 ▼
```

---

## 5. `isInitialProcessing` — der zentrale Trigger

In `ChatController::streamMessageAction` entscheidet eine einzige
Bedingung darüber, ob der Agent „von vorn beginnt" oder „eine
Folge-Nachricht anhängt":

```
isInitialProcessing := task.status == Pending
                       && incoming message == ""
                       && incoming attachments == []
```

- Treffer (`true`) → `AgentService::processTask` läuft. Es lädt die in
  `newAction` bereits aufgebaute Initial-Konversation aus
  `task.messages` und streamt deren synthetische Kontext-Tool-Calls
  via `emitInitialContextEvents` live an den Client, bevor der
  LLM-Loop startet.
- Sonst → `AgentService::continueChat` läuft. Lädt die existierende
  Konversation, hängt die neue User-Message (optional mit
  `attachments`) an und führt eine weitere Loop-Iteration aus.

Die Initial-Konversation **wird in `newAction` aufgebaut**
(`AgentService::buildInitialMessages` führt `GetPage`/`ReadTable` einmalig
aus und schreibt das Ergebnis in `tx_agent_task.messages`). Beim Auto-
Start läuft also kein erneutes Tool-Execution; `processTask` lädt nur
und streamt.

---

## 6. Glossar

- **Synthetic context calls** — die `GetPage` / `ReadTable`
  Tool-Calls, die `buildMessages` **vor** der eigentlichen
  User-Message als simulierte Assistant-Aktion einfügt, damit das
  LLM den Arbeitskontext „kennt", ohne dass ein realer Round-Trip
  nötig war.
- **`_media`** — interner Schlüssel an Tool-Messages, der rohe
  Image/PDF-Bytes für späteres Multimodal-Encoding mitführt. Wird
  in der DB persistiert und beim Senden ans LLM via
  `serializeForLlm` in eine separate User-Message umgewandelt.
- **Fresh vs Resume** — entscheidet sich am `task.status`. `Pending`
  bedeutet „newAction hat den Task gerade angelegt, Agent ist nie
  gelaufen" → `emitInitialContextEvents` streamt die in `messages`
  schon persistierte Synthese als SSE-Events. `Ended`/`Failed` → kein
  Replay; der Client lädt die Messages über `initial-messages`.
- **emitInitialContextEvents** — replayed die in `newAction`
  vorbereitete Synthese (synthetischer Assistant-Turn + Tool-Results
  + User-Message) als SSE-Events, damit die UI sie als Live-
  Aktivität sieht statt erst nach Reload als Historie.
- **`buildInitialMessages`** — die Methode, die `newAction` aufruft, um
  den vollständigen Initial-Stack (System + synthetische Tool-Calls +
  User-Message mit Attachments) zu bauen. Führt `GetPage`/`ReadTable`
  live aus und liefert ein fertiges Messages-Array, das `newAction`
  direkt in `tx_agent_task.messages` persistiert.
