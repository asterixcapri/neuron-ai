# Skill in Codex, OpenCode e Claude Code

Ricerca svolta il 1 settembre 2026 usando esclusivamente documentazione e repository ufficiali. Il sorgente è stato letto a commit fissati:

- OpenAI Codex: [`28097e98ebcb5e7eaa2e14534e60337f209a8a80`](https://github.com/openai/codex/tree/28097e98ebcb5e7eaa2e14534e60337f209a8a80)
- OpenCode: [`ebece6efd7b11401cf1e7390b5a22991b6608cc4`](https://github.com/anomalyco/opencode/tree/ebece6efd7b11401cf1e7390b5a22991b6608cc4)
- Claude Code: repository ufficiale ispezionato a [`f275fa282e76c5e5456912268f2c367a7f4f4797`](https://github.com/anthropics/claude-code/tree/f275fa282e76c5e5456912268f2c367a7f4f4797); il runtime non è pubblicato in quel repository, quindi per il comportamento interno la fonte autorevole è la documentazione ufficiale.

Le frasi marcate **Inferenza** non sono promesse esplicite del prodotto: sono conseguenze del codice osservato. Dove il runtime non è disponibile, il dettaglio è dichiarato **non verificabile**.

## Risposta breve

I tre sistemi non condividono un unico significato di “skill caricata”:

| Sistema | Consegna del `SKILL.md` | Ruolo del messaggio | Durata semantica | Dopo compaction | Stato `loaded` / middleware |
|---|---|---|---|---|---|
| Codex | injection host per menzione esplicita; normale read per selezione del modello | `user` contestuale nell'injection esplicita; tool result nel read | **solo il turno corrente** | il fragment selezionato non è preservato/reiniettato | nessun active-set durevole; dedup della sola injection del turno |
| OpenCode | tool nativo `skill(name)` | normale tool result; slash command come input user | resta visibile finché resta nella history, ma non è uno stato attivo | protetto dal pruning ordinario; fuori dalla tail della compaction completa resta solo via summary | nessun loaded-set e nessun middleware skill-specific |
| Claude Code | invocazione host/model della skill | una singola conversation message; ruolo esatto non documentato | **resto della sessione** | reinietta l'ultima invocation per skill entro budget espliciti | mantiene necessariamente un registro comportamentale per dedup/compaction; struttura interna non pubblica |

Il punto importante per Neuron è questo: **tool result**, **istruzione valida per un turno** e **skill attiva per la sessione** sono tre contratti diversi. Il formato `SKILL.md` non decide quale adottare; lo decide l'host.

## 1. OpenAI Codex

### Discovery e catalogo

Codex scopre skill repository, user, admin e system. Per il repository risale dal working directory al repository root cercando `.agents/skills`; segue le directory symlinkate e non fonde definizioni con lo stesso nome. Le location ufficiali sono documentate in [“Where Codex loads local skills”](https://developers.openai.com/codex/skills#where-codex-loads-local-skills).

Il catalogo usa progressive disclosure: nel contesto iniziale entrano nome, descrizione e path/source locator, non il body. Ha un budget massimo del 2% della context window, oppure 8.000 caratteri se la window è ignota; Codex abbrevia prima le descrizioni e può poi omettere skill con warning. Il body completo non è soggetto a quel budget quando la skill viene scelta: [documentazione ufficiale](https://developers.openai.com/codex/skills#how-chatgpt-and-codex-use-skills).

Nel sorgente il catalogo è un fragment di ruolo `developer`, marcato come `skills.catalog`: [`fragments.rs` L40-L58](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/fragments.rs#L40-L58). Il renderer produce la sezione `## Skills`, eventuali root alias e le entry disponibili: [`catalog_prompt.rs` L81-L105](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/catalog_prompt.rs#L81-L105).

### Come legge e inietta il `SKILL.md`

Codex distingue due percorsi:

1. **Menzione esplicita** (`$skill` o selezione via `/skills`). Il turn builder individua le menzioni nel nuovo input, carica soltanto quelle skill e costruisce gli item da iniettare: [`turn.rs` L821-L857](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/core/src/session/turn.rs#L821-L857). Il loader legge il file e crea un `SkillInstructions` con nome, path e contenuto: [`host_prompt.rs` L60-L108](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/host_prompt.rs#L60-L108). Quel fragment diventa un messaggio di ruolo **`user`**, con content kind `skills.selected_skill_instructions`, avvolto in `<skill>...</skill>`: [`fragments.rs` L61-L110](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/fragments.rs#L61-L110).
2. **Selezione implicita del modello.** Il catalogo ordina al modello di leggere completamente la fonte prima di agire. Per una skill filesystem ciò passa dal normale file reader e quindi il testo letto arriva come normale tool result; per package executor/orchestrator usa `skills.read`. La policy e i due meccanismi di accesso sono scritti direttamente nelle istruzioni del catalogo: [`catalog_prompt.rs` L7-L23](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/catalog_prompt.rs#L7-L23).

Il dedup visibile nel turn builder evita che la stessa skill host venga iniettata due volte quando è già stata fornita dall'extension layer nello stesso turno: [`turn.rs` L895-L909](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/core/src/session/turn.rs#L895-L909). Non è un registro di skill attive nella conversazione.

### Durata nei turni e compaction

La semantica è esplicita: “use that skill for that turn” e “do not carry skills across turns unless re-mentioned”: [`catalog_prompt.rs` L7-L12](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/catalog_prompt.rs#L7-L12). Quindi la presenza fisica di un vecchio fragment nella history non autorizza il modello a considerare la skill ancora attiva.

La compaction conferma che non esiste una reiniezione delle skill selezionate. I messaggi `user` contestuali vengono esclusi dal parsing dei veri messaggi utente: [`event_mapping.rs` L64-L100](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/core/src/event_mapping.rs#L64-L100). La compaction conserva solo gli item riconosciuti come `TurnItem::UserMessage`: [`compact.rs` L541-L570](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/core/src/compact.rs#L541-L570). Di conseguenza il `<skill>` contestuale non entra nella replacement history; viene ricostruito il catalogo generale, non il body della skill scelta.

**Conclusione fattuale:** Codex non ha bisogno di proteggere una “skill attiva” dopo compaction perché il contratto corrente è turn-scoped.

### Risorse, script, sicurezza e moduli

Il catalogo ordina di leggere soltanto i riferimenti necessari, risolvere i path relativi rispetto alla directory della skill, preferire gli script già presenti e riusare asset/template: [`catalog_prompt.rs` L27-L39](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/catalog_prompt.rs#L27-L39). Lettura ed esecuzione passano per i normali tool e relative policy; `agents/openai.yaml` può inoltre dichiarare dipendenze e disabilitare l'invocazione implicita: [metadata opzionale](https://developers.openai.com/codex/skills#optional-metadata).

I moduli principali sono separati per responsabilità: discovery/catalog, rendering del catalogo, loader host, fragment contestuali e integrazione nel turn builder. Lo stato session/thread/turn presente nel sottosistema serve a snapshot, provider e injection; dal flusso sopra non emerge un dominio `ActiveSkill` durevole.

## 2. OpenCode

### Discovery e catalogo

OpenCode scopre skill globali e di progetto da `.opencode`, `.claude` e `.agents`, più fonti configurate e URL. Il codice costruisce un catalogo per istanza con `skills` e directory di provenienza: [`skill/index.ts` L82-L103](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/skill/index.ts#L82-L103). La scansione delle location compatibili è in [`skill/index.ts` L173-L227](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/skill/index.ts#L173-L227), mentre parsing e caricamento nel catalogo sono in [`skill/index.ts` L235-L317](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/skill/index.ts#L235-L317). La [documentazione ufficiale](https://opencode.ai/docs/skills) descrive le stesse location e la risalita fino al git worktree.

Il body non viene pre-caricato nel prompt. Il system prompt aggiunge l'istruzione di usare il tool `skill` quando il task corrisponde alla descrizione: [`session/system.ts` L105-L117](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/system.ts#L105-L117). Il catalogo XML `<available_skills>` contiene nome, descrizione e location, filtrati per permessi: [`skill/index.ts` L321-L345](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/skill/index.ts#L321-L345).

Più precisamente, non è soltanto un “prompt iniziale”: OpenCode riassembla il system array e vi aggiunge il catalogo a **ogni iterazione verso il provider**: [`session/prompt.ts` L1257-L1286](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/prompt.ts#L1257-L1286).

### Come legge e inietta il `SKILL.md`

Il modello chiama il tool nativo:

```text
skill({ name: "git-release" })
```

Il tool risolve l'entry già parsata, applica il permesso `skill` e restituisce `<skill_content>` con body Markdown, base directory e fino a dieci path di file di supporto: [`tool/skill.ts` L12-L67](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/tool/skill.ts#L12-L67). Non rilegge `SKILL.md` tramite il generico `read` al momento dell'invocazione.

Il risultato è un normale **tool result**. Il processor completa un `ToolPart` nel messaggio assistant: [`session/processor.ts` L383-L413](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/processor.ts#L383-L413); la conversione provider lo emette come output associato alla tool call: [`session/message-v2.ts` L290-L323](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/message-v2.ts#L290-L323), [`L404-L414`](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/message-v2.ts#L404-L414).

Esiste anche un percorso esplicito alternativo: ogni skill viene registrata come slash command e il body diventa il template dell'input user: [`command/index.ts` L134-L151](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/command/index.ts#L134-L151).

### Durata nei turni e compaction

Non c'è una regola turn-scoped come in Codex. **Inferenza dal normale modello di history:** il tool result resta visibile nelle successive iterazioni e nei turni successivi finché appartiene alla active history inviata al provider. Ciò non lo rende uno stato “active”: è semplicemente un vecchio messaggio.

La compaction completa non reinietta le skill. Tool call e result entrano nel materiale da riassumere, con output limitato a 2.000 caratteri: [`session/compaction.ts` L28-L31](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/compaction.ts#L28-L31), [`L51-L84`](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/compaction.ts#L51-L84). Soltanto la tail recente resta integralmente nella replacement history: [`session/compaction.ts` L223-L268](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/compaction.ts#L223-L268).

OpenCode fa però una scelta mirata: il tool `skill` è nella lista dei tool protetti dal **pruning ordinario**, quindi il suo output non viene sostituito con `[Old tool result content cleared]`: [`session/compaction.ts` L28-L32](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/compaction.ts#L28-L32), [`L288-L304`](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/session/compaction.ts#L288-L304). Questa protezione non equivale alla reiniezione dopo una compaction completa.

Quindi, dopo compaction, le istruzioni sopravvivono soltanto se il summary le conserva o se il tool result rimane nella tail. Il catalogo generale invece ritorna comunque nel system prompt alla successiva provider call, consentendo al modello di richiamare la skill.

### Risorse, script, sicurezza e stato

Il tool non carica automaticamente le risorse e non esegue script. Comunica la base directory e un campione dei file; il modello usa poi i normali read/shell tool, soggetti ai relativi permessi. La documentazione conferma che soltanto il body completo viene caricato on demand: [OpenCode, “Recognize tool description”](https://opencode.ai/docs/skills#recognize-tool-description).

La sicurezza specifica è concentrata sul permesso pattern-based `skill`: `allow`, `ask` o `deny`; `deny` nasconde la skill dal catalogo e rifiuta il load: [documentazione ufficiale](https://opencode.ai/docs/skills#configure-permissions). Le successive letture o esecuzioni restano governate dai normali permessi dei rispettivi tool.

Lo state del modulo contiene catalogo e directory, non un `LoadedSkill`: [`skill/index.ts` L82-L103](https://github.com/anomalyco/opencode/blob/ebece6efd7b11401cf1e7390b5a22991b6608cc4/packages/opencode/src/skill/index.ts#L82-L103). `skill.execute` fa lookup, permission check e return, senza mutare un active-set. Non c'è middleware skill-specific; persistenza e perdita sono proprietà della normale message history e della compaction generica.

## 3. Claude Code

### Discovery e catalogo

Claude Code scopre skill enterprise, personal (`~/.claude/skills`), project (`.claude/skills`) e plugin. Enterprise prevale su personal, personal su project; le skill plugin sono namespaced. Supporta symlink con dedup del target e skill annidate che diventano disponibili quando Claude legge o modifica file nel relativo subtree: [documentazione ufficiale, location e precedenze](https://code.claude.com/docs/en/slash-commands#where-skills-live), [discovery automatica](https://code.claude.com/docs/en/slash-commands#automatic-discovery-from-parent-and-nested-directories).

In una sessione regolare entrano inizialmente nel contesto le descrizioni delle skill invocabili dal modello; il body completo entra soltanto all'invocazione. Le skill con `disable-model-invocation: true` non pubblicizzano la description al modello, mentre i subagent con skill preloaded ricevono il body completo allo startup: [tabella di invocation e loading](https://code.claude.com/docs/en/slash-commands#control-who-invokes-a-skill).

La documentazione non specifica se quel catalogo sia materializzato come system, developer o altra sezione interna del prompt. Il repository ufficiale ispezionato non contiene il runtime necessario a verificarlo.

### Come legge e inietta il `SKILL.md`

La skill può essere invocata dall'utente (`/skill-name`) o dal modello quando la description corrisponde. Prima dell'invio, Claude Code renderizza argomenti e dynamic context; i comandi `!` vengono eseguiti e il loro output sostituisce il placeholder. Soltanto il prompt renderizzato viene consegnato a Claude: [dynamic context](https://code.claude.com/docs/en/slash-commands#inject-dynamic-context), [esecuzione e failure](https://code.claude.com/docs/en/slash-commands#how-injected-commands-run).

La garanzia pubblica è precisa: il contenuto renderizzato entra nella conversazione come **un singolo messaggio**. Il ruolo protocollare esatto di quel messaggio non è documentato e non è verificabile dal repository pubblico; chiamarlo tool result, user o system sarebbe una supposizione.

Con `context: fork`, invece, il body diventa il prompt del subagent isolato e il subagent non riceve la history della conversazione principale: [forked skills](https://code.claude.com/docs/en/slash-commands#run-skills-in-a-subagent).

### Durata nei turni, dedup e compaction

Claude Code offre il contratto più durevole dei tre:

- il singolo messaggio resta nella conversazione nei turni successivi;
- il file non viene riletto nei turni successivi;
- una reinvocazione con identico contenuto renderizzato aggiunge soltanto una nota “already loaded”;
- se argomenti o dynamic context cambiano il rendering, viene aggiunta una nuova copia completa;
- dopo auto-compaction viene riattaccata l'invocazione più recente di ogni skill, limitata ai primi 5.000 token;
- tutte le skill reiniettate condividono 25.000 token, riempiti dalla più recente, quindi skill più vecchie possono essere eliminate.

Questi sono fatti documentati in [“Skill content lifecycle”](https://code.claude.com/docs/en/slash-commands#skill-content-lifecycle).

I permessi hanno un lifecycle diverso dalle istruzioni: `allowed-tools`, model override e restriction valgono per il turno che invoca la skill e si azzerano al messaggio successivo, mentre le istruzioni restano: [pre-approval dei tool](https://code.claude.com/docs/en/slash-commands#pre-approve-tools-for-a-skill).

### Risorse, script, sicurezza e stato

Riferimenti e script vivono accanto al `SKILL.md`; `${CLAUDE_SKILL_DIR}` consente path stabili. I riferimenti possono essere caricati quando servono; gli script invocati dal body passano per Bash o PowerShell e soltanto l'output renderizzato raggiunge Claude. Un comando fallito o non autorizzato abortisce l'invocazione prima che Claude veda il body: [variabili e skill directory](https://code.claude.com/docs/en/slash-commands#available-variables), [comandi iniettati](https://code.claude.com/docs/en/slash-commands#how-injected-commands-run).

Non esiste un “middleware skill” pubblico. Gli hooks sono una capability di enforcement/lifecycle separata e possono durare per la sessione, ma la documentazione attribuisce persistenza, dedup e reiniezione direttamente al runtime delle skill.

**Inferenza necessaria ma limitata:** per produrre “already loaded” e riattaccare l'ultima invocation di ogni skill, Claude Code deve conservare almeno identità, contenuto renderizzato recente e ordine d'invocazione. Questo è uno stato loaded comportamentale. Non sappiamo però il nome del tipo, dove sia salvato, né se Anthropic lo chiami `ActiveSkills`; il runtime non è pubblico.

## Confronto per la decisione Neuron

### Se il requisito è “il modello può usare una skill quando serve”

Il disegno OpenCode è sufficiente:

```text
catalogo -> skill_load(name) -> ToolResultMessage -> normale history
```

Non serve un oggetto `ActiveSkills` e non serve un middleware dedicato. Le istruzioni restano disponibili finché il tool result resta nel contesto; dopo compaction la garanzia è best-effort.

### Se il requisito è “la skill vale soltanto per la richiesta corrente”

Il disegno Codex lo rende esplicito. L'host deve iniettare o caricare il body nel turno e le istruzioni del catalogo devono vietarne il carry-over. Anche qui non serve stato durevole.

### Se il requisito è “la skill resta attiva per tutta la sessione, anche dopo compaction”

Il disegno Claude Code richiede stato reale, anche se non necessariamente un oggetto pubblico chiamato `ActiveSkills`:

```text
invocation registry
  -> dedup per contenuto renderizzato
  -> ultima invocation per skill
  -> reiniezione post-compaction con budget
```

Un middleware è soltanto una possibile sede tecnica di questa policy in Neuron. Non è ciò che rende una skill una skill; serve esclusivamente se Neuron sceglie la garanzia session-persistent e il normale layer di history/compaction non offre già un hook più diretto.

## Fatti da non confondere

1. **Catalogo** significa elenco leggero per la selezione: nome, descrizione e locator. Non significa insieme di skill caricate.
2. **Loaded** può significare soltanto “il body è comparso una volta nella history” (OpenCode) oppure “il runtime promette di mantenerlo e reiniettarlo” (Claude Code).
3. **Active** è un termine di dominio aggiuntivo. Codex non lo usa perché la skill è turn-scoped; OpenCode non ne ha bisogno perché si affida alla history; Claude ne implementa il comportamento, ma non ne pubblica il nome interno.
4. **Tool result message va bene** se si accetta la persistenza della normale history. Non basta, da solo, a promettere sopravvivenza dopo pruning o compaction.
5. **Middleware** non appare come concetto fondamentale in nessuno dei tre. Il problema fondamentale è scegliere il lifecycle; la collocazione tecnica viene dopo.
