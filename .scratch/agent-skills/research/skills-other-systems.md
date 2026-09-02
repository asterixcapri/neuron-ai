# Agent Skills negli altri sistemi

Ricerca svolta il 1 settembre 2026. Sono state usate esclusivamente fonti primarie: specifica e guida ufficiale Agent Skills, documentazione ufficiale e repository sorgente ufficiali. Pi è il caso centrale perché offre l'implementazione più piccola e leggibile tra quelle esaminate.

Quando una proprietà non è documentata o il runtime è chiuso, il testo la segnala come **non stabilita** anziché ricostruirla per supposizione. Le frasi marcate **Inferenza** derivano direttamente dal flusso osservabile, ma non sono promesse del prodotto.

## Conclusione in breve

Il nucleo comune è molto più piccolo di un lifecycle di “skill attive”:

1. discovery di directory contenenti `SKILL.md`;
2. catalogo iniziale di nome e descrizione;
3. lettura lazy del file completo tramite un normale file reader oppure un tool dedicato;
4. istruzioni consegnate al modello nella normale conversation context;
5. risorse e script usati solo quando le istruzioni li richiedono.

Pi, Claude e il percorso implicito di Codex usano il normale file/bash tool. Microsoft Agent Framework usa invece tre tool dedicati. In entrambi i casi il testo letto arriva inizialmente come risultato di tool e rimane efficace finché resta nella history inviata al modello.

Nessuno dei sistemi ispezionabili adotta il preciso disegno Neuron `LoadedSkill` + middleware che copia le istruzioni nel system prompt dopo la compaction:

- Pi non mantiene alcun registro runtime delle skill caricate.
- Codex dichiara le skill turn-scoped: “non portarle ai turni successivi se non sono nuovamente menzionate”.
- Microsoft Agent Framework non mantiene un loaded-set; restituisce il contenuto dal tool e si affida alla normale session history.
- Pydantic AI, sistema analogo ma non implementazione completa dello standard, ricostruisce dalla history gli ID delle capability caricate; non crea un value object applicativo `LoadedSkill`.

La [guida ufficiale per implementatori](https://agentskills.io/client-implementation/adding-skills-support#step-5-manage-skill-context-over-time), tuttavia, consiglia di proteggere le istruzioni dalla compaction e di deduplicare le attivazioni. Questa è una scelta di lifecycle del client, non un requisito del [formato Agent Skills](https://agentskills.io/specification). Ne consegue che Neuron deve decidere esplicitamente fra:

- semantica minima, come Pi: le istruzioni sono un normale tool result e possono essere perse dalla compaction;
- semantica durevole, come raccomandato dalla guida: il contenuto della skill deve essere marcato/protetto o reiniettato.

La seconda non è necessaria per “supportare `SKILL.md`” in senso stretto. È una garanzia aggiuntiva del runtime.

## Baseline: cosa prescrive davvero lo standard

La specifica definisce un formato, non un'architettura PHP:

- una skill è una directory con `SKILL.md` obbligatorio e `scripts/`, `references/`, `assets/` opzionali;
- il frontmatter richiede `name` e `description`;
- il body Markdown contiene le istruzioni;
- progressive disclosure: metadata all'avvio, `SKILL.md` completo all'attivazione, risorse quando servono;
- le lingue eseguibili e l'enforcement di `allowed-tools` dipendono dal client.

Fonti: [specifica, struttura e frontmatter](https://agentskills.io/specification#directory-structure), [progressive disclosure](https://agentskills.io/specification#progressive-disclosure), [risorse opzionali](https://agentskills.io/specification#optional-directories).

La guida ufficiale per chi costruisce un client presenta due attivazioni equivalenti:

- normale file reader: il modello legge il path del `SKILL.md` e riceve il file come tool result;
- tool dedicato: per esempio `activate_skill(name)`, utile quando non esiste un file reader o servono confinement, wrapping, permessi e telemetria.

La guida dice anche che il record minimo in memoria è `name`, `description`, `location`; il body può essere letto all'attivazione. Non introduce un oggetto “active skill” come parte dello standard. Fonti: [record minimo e catalogo](https://agentskills.io/client-implementation/adding-skills-support#step-2-parse-skillmd-files), [due pattern di attivazione](https://agentskills.io/client-implementation/adding-skills-support#model-driven-activation), [gestione nel tempo](https://agentskills.io/client-implementation/adding-skills-support#step-5-manage-skill-context-over-time).

## Matrice comparativa

| Sistema | Catalogo iniziale | Caricamento | Dove finiscono le istruzioni | Lifecycle documentato |
|---|---|---|---|---|
| Pi | XML: nome, descrizione, path assoluto | normale `read` oppure espansione `/skill:name` | tool result; per slash invocation, testo del messaggio utente | history normale; nessuna protezione/dedup runtime |
| OpenAI Codex | nome, descrizione, path/source locator, con budget | normale read per selezione implicita; injection host per `$skill` | tool result o fragment utente `<skill>` | esplicitamente turn-scoped; nessun `LoadedSkill` durevole |
| Claude Code / Anthropic | nome e descrizione nel system prompt | `bash` legge `SKILL.md` | output bash nella context window | persistenza post-compaction non documentata |
| GitHub Copilot | descrizioni delle skill scoperte | host inietta `SKILL.md` quando rilevante o via slash | agent context/conversation | durata e compaction non documentate; SDK supporta preload eager |
| Cursor | descrizioni delle skill scoperte | Agent legge/inietta la skill; slash o automatico | context del messaggio; Custom Mode per sessione | slash: un messaggio; Custom Mode: intera sessione |
| Microsoft Agent Framework | XML nel system prompt | `load_skill`, `read_skill_resource`, `run_skill_script` | normali function results in history | nessun loaded-set nel provider; catalogo/tool ricostruiti prima di ogni run |
| Pydantic AI, analogo | ID + descrizione | `load_capability` | tool result; tool/settings/hooks sbloccati | loaded IDs ricostruiti dalle call/result nella history |

## Pi: il riferimento minimale

Progetto identificato: [`badlogic/pi-mono`](https://github.com/badlogic/pi-mono), commit analizzato [`b8b873b9872db04a938fb4357b5e8e824ddc051c`](https://github.com/badlogic/pi-mono/tree/b8b873b9872db04a938fb4357b5e8e824ddc051c).

### Discovery e catalog entry

Pi usa un tipo `Skill` molto piccolo: `name`, `description`, `filePath`, `baseDir`, provenance e `disableModelInvocation`. Il body non fa parte dell'entry: [source, `skills.ts` L67-L81](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/skills.ts#L67-L81).

La discovery è ricorsiva. Quando trova un `SKILL.md`, tratta quella directory come root della skill e non discende ulteriormente. Rispetta `.gitignore`, `.ignore` e `.fdignore`, e segue symlink validi: [`skills.ts` L160-L275](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/skills.ts#L160-L275).

Durante la discovery Pi legge il Markdown per estrarre il frontmatter, ma conserva nel catalogo solo metadata e path. Una descrizione mancante esclude la skill; altre violazioni producono warning: [`skills.ts` L277-L345](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/skills.ts#L277-L345).

Per il dedup di discovery canonicalizza i file, elimina alias dello stesso `SKILL.md` e, nelle collisioni di nome, usa first-wins con warning: [`skills.ts` L407-L506](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/skills.ts#L407-L506).

### Prompt e caricamento

Il system prompt contiene un catalogo XML con nome, descrizione e location assoluta e ordina al modello di usare il normale `read`: [`skills.ts` L347-L381](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/skills.ts#L347-L381). Il catalogo viene incluso solo se il tool `read` è disponibile: [`system-prompt.ts` L151-L168](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/system-prompt.ts#L151-L168).

Il modello chiama quindi:

```text
read('/absolute/path/to/skill/SKILL.md')
```

Non esiste `skill_load`. `read` restituisce il testo come un normale risultato: [`read.ts` L209-L275](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/tools/read.ts#L209-L275). L'agent loop lo materializza come `toolResult`: [`agent-loop.ts` L784-L802](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/agent/src/agent-loop.ts#L784-L802), e la sessione persiste user, assistant e tool result: [`agent-session.ts` L675-L691](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/agent-session.ts#L675-L691).

Questo risponde direttamente alla domanda Neuron: **sì, un tool result è sufficiente affinché il modello continui a vedere le istruzioni nei turni successivi, finché quel messaggio rimane nella history trasmessa al provider**.

L'invocazione esplicita `/skill:name args` segue un'altra strada: Pi legge il file lato host, rimuove il frontmatter, avvolge body e base directory in `<skill>` e sostituisce il comando con quel testo nel messaggio utente: [`agent-session.ts` L1349-L1377](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/agent-session.ts#L1349-L1377).

### Risorse, sicurezza e compaction

Le directory `scripts/`, `references/` e `assets/` sono convenzioni. Il modello usa i normali tool sui path relativi; non esistono un resource loader o uno script executor specifici: [documentazione Pi, risorse](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/docs/skills.md#L93-L136).

Il confine di sicurezza è quello generale dell'agent, non la directory della skill. `read` accetta path assoluti e `~` e non applica containment rispetto alla skill: [`path-utils.ts` L44-L117](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/tools/path-utils.ts#L44-L117). Pi mitiga a monte con project trust e avverte di esaminare skill e script: [documentazione Pi, sicurezza](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/docs/skills.md#L20-L34).

Non esiste un registro di skill già lette, quindi il modello può rileggere la stessa skill. Durante compaction, i tool result entrano nel materiale da riassumere ma vengono troncati a 2.000 caratteri: [`compaction/utils.ts` L88-L149](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/compaction/utils.ts#L88-L149). Il prompt chiede al summary di preservare vincoli importanti ma non tratta le skill in modo speciale: [`compaction.ts` L467-L498](https://github.com/badlogic/pi-mono/blob/b8b873b9872db04a938fb4357b5e8e824ddc051c/packages/coding-agent/src/core/compaction/compaction.ts#L467-L498).

**Inferenza:** dopo compaction la sopravvivenza delle istruzioni è best-effort del sommario, non una garanzia deterministica.

### Forma dei moduli

Pi non ha un sottosistema lifecycle. I touchpoint principali sono:

- `core/skills.ts`: discovery, parsing, validazione e rendering catalogo;
- `resource-loader.ts`: fonti, provenance e reload;
- `system-prompt.ts`: inserimento catalogo;
- `agent-session.ts`: espansione slash e persistenza sessione;
- il generico `core/tools/read.ts` e la compaction generica.

La lezione per Neuron non è replicare questi file, ma che l'astrazione minima è una **catalog entry**, non una “skill attiva”.

## OpenAI Codex

Fonti ufficiali: [documentazione OpenAI sulle skills](https://developers.openai.com/codex/skills) e repository [`openai/codex`](https://github.com/openai/codex), commit analizzato [`28097e98ebcb5e7eaa2e14534e60337f209a8a80`](https://github.com/openai/codex/tree/28097e98ebcb5e7eaa2e14534e60337f209a8a80).

Codex scansiona scope repository, user, admin e system. Nel repository risale da CWD al repository root cercando `.agents/skills`; supporta directory symlinkate. Se due skill hanno lo stesso nome non le fonde e possono comparire entrambe nel selector: [documentazione, “Where Codex loads local skills”](https://developers.openai.com/codex/skills#where-codex-loads-local-skills).

Il prompt iniziale contiene nome, descrizione e path. Ha un budget massimo pari al 2% della context window o 8.000 caratteri quando la window è ignota; prima abbrevia descrizioni, poi può omettere entry con warning: [documentazione, progressive disclosure](https://developers.openai.com/codex/skills#how-chatgpt-and-codex-use-skills).

Codex permette:

- invocazione esplicita tramite `$skill` o `/skills`;
- invocazione implicita quando il task corrisponde alla description.

Per le skill filesystem il prompt istruisce il modello a leggere tutto il `SKILL.md` prima di agire e a leggere solo le risorse necessarie. Il sorgente corrente formula una policy importante: la skill vale **per quel turno** e non va portata ai turni successivi senza nuova menzione: [`catalog_prompt.rs` L7-L23](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/catalog_prompt.rs#L7-L23).

Con una menzione esplicita, l'host legge il file e costruisce un fragment identificato da nome, path e contenuto: [`host_prompt.rs` L60-L108](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/host_prompt.rs#L60-L108). Il fragment è un messaggio di ruolo `user` avvolto in `<skill>`: [`fragments.rs` L61-L108](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/ext/skills/src/fragments.rs#L61-L108). Il turn builder inietta solo le skill menzionate in quel turno e deduplica eventuali injection fornite anche dall'extension layer: [`turn.rs` L821-L909](https://github.com/openai/codex/blob/28097e98ebcb5e7eaa2e14534e60337f209a8a80/codex-rs/core/src/session/turn.rs#L821-L909).

Nel percorso implicito, il modello usa il normale meccanismo di lettura indicato dal catalogo. Risorse e script vengono letti/eseguiti attraverso i normali tool; per skill remote/executor esiste anche `skills.read`, ma non un `ActiveSkills` applicativo.

Il codice è oggi più articolato di Pi perché unifica skill host, executor, orchestrator, plugin, alias di path, budget e telemetria. I concetti sono separati in un crate/extension `skills` con catalog, provider, loader, render, selection, fragments e state per session/thread/turn. Questa complessità risponde a molteplici autorità e superfici, non alla semplice lettura di una directory locale.

## Claude Code e Anthropic Agent Skills

Anthropic documenta esplicitamente tre livelli:

1. nome e descrizione sempre nel system prompt;
2. `SKILL.md` letto quando la skill si attiva;
3. references lette e script eseguiti quando servono.

Quando il task corrisponde alla description, Claude usa `bash` per leggere `SKILL.md`; solo allora il testo entra nella context window. Gli script vengono eseguiti via bash e soltanto il loro output entra nel contesto: [Anthropic, “How Skills work”](https://platform.claude.com/docs/en/agents-and-tools/agent-skills/overview#how-skills-work).

Le skill Claude Code vivono in `~/.claude/skills/` o `.claude/skills/`; sul Claude API vivono invece in container sandboxed senza rete e senza installazione runtime di pacchetti: [Claude Code e runtime constraints](https://platform.claude.com/docs/en/agents-and-tools/agent-skills/overview#where-skills-work).

Sicurezza: Anthropic considera una skill equivalente a software da installare, raccomanda audit di tutti i file e segnala rischi di tool misuse, data exposure ed external prompt injection: [security considerations](https://platform.claude.com/docs/en/agents-and-tools/agent-skills/overview#security-considerations).

Il runtime Claude Code non è open source e la documentazione non promette come le istruzioni vengano preservate dopo compaction, né espone un loaded-set. **Inferenza limitata:** poiché la lettura avviene via bash, il contenuto entra inizialmente come normale output di tool, come in Pi; non è lecito inferire una reiniezione non documentata.

## GitHub Copilot

Copilot supporta skill di progetto in `.github/skills`, `.claude/skills` e `.agents/skills`, e skill personali in `~/.copilot/skills` e `~/.agents/skills`: [GitHub Docs, overview](https://docs.github.com/en/copilot/concepts/agents/about-agent-skills).

Copilot decide la rilevanza usando la description. Quando sceglie una skill, GitHub dice che il `SKILL.md` viene “injected in the agent's context”; script ed esempi della directory diventano disponibili accanto alle istruzioni: [GitHub Docs, “How Copilot uses agent skills”](https://docs.github.com/en/copilot/how-tos/copilot-on-github/customize-copilot/customize-cloud-agent/add-skills#how-copilot-uses-agent-skills).

Copilot CLI permette reload, list/info e slash invocation. Le collisioni seguono una priorità documentata fra scope; le remote organization skills sono fetched on demand: [Copilot CLI skill reference](https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-command-reference#skills-reference).

Sicurezza: `allowed-tools` può pre-approvare shell/bash, ma GitHub avverte che rimuovere la conferma consente a skill o prompt injection di eseguire codice arbitrario; raccomanda preview e review delle skill non verificate: [GitHub Docs, esecuzione script](https://docs.github.com/en/copilot/how-tos/copilot-on-github/customize-copilot/customize-cloud-agent/add-skills#enabling-a-skill-to-run-a-script).

La durata della skill automatica e la preservazione post-compaction non sono documentate. Esiste però un secondo modello nel Copilot SDK: le skill nominate nella configurazione di un custom agent vengono eager-preloaded per intero all'avvio, mentre i subagent non le ereditano: [Copilot SDK, custom skills](https://docs.github.com/en/copilot/how-tos/copilot-sdk/features/skills). Questo è un preload configurativo, non lo stesso lifecycle delle skill auto-selezionate.

## Cursor

Cursor è comparabile e ora documenta Agent Skills direttamente. Scopre automaticamente skill project e user da `.agents/skills` e `.cursor/skills`, oltre alle directory compatibili Claude e Codex. La discovery è ricorsiva e le skill in sottodirectory di un monorepo vengono scoped ai file sotto quella directory: [Cursor Docs, skill directories](https://cursor.com/docs/skills#skill-directories).

Il modello vede le skill disponibili e decide dalla description. Una skill può essere invocata con slash; l'invocazione slash si applica a **un messaggio**. Per mantenerla per tutta la sessione, Cursor richiede un Custom Mode: [Cursor Docs, “How skills work”](https://cursor.com/docs/skills#how-skills-work).

Le risorse sono progressive: `references/` lette on demand, `scripts/` eseguiti tramite i tool dell'agent e `assets/` usati come file statici. Cursor supporta inoltre `paths` per surface filtering e `disable-model-invocation` per rendere una skill solo esplicita: [Cursor Docs, frontmatter e optional directories](https://cursor.com/docs/skills#skillmd-file-format).

Questa semantica offre una lezione utile: “skill caricata” non significa necessariamente “comportamento permanente della conversazione”. Cursor distingue deliberatamente l'allegato al messaggio dal mode persistente.

Il runtime è chiuso; dedup interno, formato esatto dell'injection e compaction non sono stabiliti dalla documentazione.

## Microsoft Agent Framework: libreria open source conforme

Repository ufficiale [`microsoft/agent-framework`](https://github.com/microsoft/agent-framework), commit analizzato [`baf0ea5252eb3faa232b811c1c4d95771afd10ed`](https://github.com/microsoft/agent-framework/tree/baf0ea5252eb3faa232b811c1c4d95771afd10ed). È l'implementazione di libreria più vicina al problema Neuron.

Il design pubblico ha tre elementi:

- `SkillsProvider`, un context provider che pubblicizza skill e registra tool;
- `SkillsSource`, con implementazioni filesystem, inline, class-based e MCP;
- source decorators per aggregazione, filtering, dedup e caching.

Fonte: [documentazione Microsoft Agent Skills](https://learn.microsoft.com/en-us/agent-framework/agents/skills#providing-skills-to-an-agent).

Prima di ogni run, il provider ottiene la lista dalla source, genera un system prompt XML ordinato per nome e registra sempre:

- `load_skill(skill_name)`;
- `read_skill_resource(skill_name, resource_name)`;
- `run_skill_script(skill_name, script_name, args)`.

Il source mostra che `load_skill` fa soltanto lookup e `return await skill.get_content()`: [`_skills.py`, provider e tool](https://github.com/microsoft/agent-framework/blob/baf0ea5252eb3faa232b811c1c4d95771afd10ed/python/packages/core/agent_framework/_skills.py#L1796-L1819), [`_load_skill`](https://github.com/microsoft/agent-framework/blob/baf0ea5252eb3faa232b811c1c4d95771afd10ed/python/packages/core/agent_framework/_skills.py#L2569-L2596). Non scrive uno stato loaded e non reinietta nel system prompt. Le istruzioni finiscono nel normale function result gestito dall'agent loop/session.

**Inferenza verificata sul provider:** la persistenza dipende dalla history della sessione; `SkillsProvider` non offre di per sé una garanzia speciale oltre la compaction. Il suo stato/caching riguarda l'elenco delle definizioni, non quali skill il modello abbia già caricato.

La parte sicurezza è più forte di Pi e rilevante per Neuron:

- resource read protetto da path traversal e symlink escape;
- root configurata come trust boundary;
- ogni tool skill richiede approval per default;
- regola pronta per auto-approvare solo `load_skill` e `read_skill_resource`, lasciando `run_skill_script` in approvazione manuale;
- senza uno script runner configurato, gli script filesystem non possono essere eseguiti.

Fonti: [documentazione Microsoft, approval e script runner](https://learn.microsoft.com/en-us/agent-framework/agents/skills#use-agent-skills-with-harness-agent), [source `FileSkillsSource`](https://github.com/microsoft/agent-framework/blob/baf0ea5252eb3faa232b811c1c4d95771afd10ed/python/packages/core/agent_framework/_skills.py#L2790-L2875).

La versione Python concentra il dominio in un grande `_skills.py`, integrandolo attraverso l'interfaccia generica `ContextProvider` e il middleware generico di tool approval. Questa è una scelta opposta a molti piccoli file, non una riduzione dei concetti: modelli, source, decorators, discovery, provider e tre tool restano responsabilità distinte nello stesso modulo.

## Pydantic AI: analogo utile, non supporto completo dello standard

Pydantic AI non offre un repository Agent Skills conforme out of the box; la sua documentazione mostra come adattare Markdown con poche righe. Va quindi trattato come confronto di lifecycle, non come seconda implementazione dello standard.

Una capability `defer_loading=True` compare inizialmente come `id + description`. Il modello chiama `load_capability`; le istruzioni tornano come tool result e gli eventuali tool/settings/hooks del bundle diventano disponibili dalla richiesta successiva: [Pydantic AI, on-demand capabilities](https://ai.pydantic.dev/capabilities/on-demand/#loading-skills-from-markdown-files).

La scelta interessante è lo stato:

- le capability restano loaded per il resto del run;
- gli ID loaded vengono ricostruiti dalle coppie `load_capability` call/result nella message history;
- la definizione resta nel codice, la history conserva soltanto l'identità;
- tool disponibili sono ricostruiti tramite delta persistiti nella history.

Fonte: [`pydantic-ai`, “Resumable across runs”](https://github.com/pydantic/pydantic-ai/blob/main/docs/capabilities/on-demand.md#resumable-across-runs).

Questo evita un `ActiveSkills` parallelo alla conversazione. Non risolve automaticamente la perdita del testo delle istruzioni se la compaction rimuove proprio il tool result; risolve invece la ricostruzione del bundle attivo quando le coppie di history sono preservate.

## Conseguenze concrete per Neuron

### 1. Il nome corretto del dato di discovery

L'evidenza converge su un record piccolo di catalogo. `SkillCatalogEntry` è più preciso di `SkillMetadata` se il tipo pubblico contiene soltanto:

```php
name
description
```

Pi lo chiama `Skill`, Codex `SkillMetadata`, Microsoft espone un frontmatter completo. Nessun nome esterno è normativo. Nel dominio Neuron, “catalog entry” dice esattamente ruolo e contenuto e non promette tutto il frontmatter.

### 2. `LoadedSkill` non è necessario nel modello minimo

Pi, Claude e Microsoft dimostrano che `skill_load` può semplicemente restituire le istruzioni. La history è già il contenitore. Aggiungere `LoadedSkill` significa scegliere una semantica durevole ulteriore; non deriva automaticamente dallo standard.

### 3. Il middleware dipende dalla garanzia desiderata

Se Neuron accetta la semantica Pi:

```text
skill_load -> ToolResultMessage -> normale history
```

non serve middleware. Il modello vede la skill nei turni successivi finché il tool result rimane nel contesto.

Se Neuron promette “per tutta la sessione anche dopo trimming/summarization”, serve una policy esplicita. La guida ufficiale suggerisce prima di tutto **proteggere/marcare il tool result durante compaction**, non necessariamente copiare il testo nel system prompt. Le opzioni in ordine di invasività sono:

1. marcare il `ToolResultMessage` della skill come non eliminabile;
2. riconoscere il wrapping strutturato durante compaction e preservarlo;
3. ricostruire/reiniettare tramite middleware se il framework non offre una protezione dei messaggi.

Il terzo è un fallback architetturale, non il modello canonico osservato negli altri sistemi.

### 4. Toolkit e middleware separati resta una seam corretta

Se si sceglie la garanzia durevole, registrarli separatamente evita che `ToolkitInterface` dipenda da Workflow. Ma il middleware non dovrebbe creare un nuovo linguaggio di “active skills”: può identificare i risultati di `SkillLoadTool` e preservare quel contenuto.

### 5. Resource tool: scelta di ambiente, non obbligo dello standard

- Con accesso filesystem generale: Pi/Codex/Claude usano il normale read; un `SkillLoadResourceTool` è ridondante.
- Senza filesystem generale o con confinement: Microsoft dimostra il valore di `read_skill_resource`.

Per Neuron, che deve funzionare anche in agent senza file tool e deve confinare le risorse alla directory della skill, un resource tool dedicato è giustificabile. La ragione è capability + sicurezza, non progressive disclosure in sé.

### 6. Script executor va escluso dalla prima versione

Pi/Codex/Claude delegano allo shell generale; Microsoft richiede runner esplicito e approval. Aggiungere un executor nel modulo Neuron introdurrebbe process management, sandboxing, approval, timeout e audit. Non serve per leggere istruzioni e risorse ed è corretto non includerlo ora.

## Raccomandazione minima

Per il perimetro discusso, senza composite repository e senza script executor:

```text
SkillCatalogEntry
SkillRepositoryInterface
FileSystemSkillRepository
SkillToolkit
SkillLoadTool
SkillLoadResourceTool
```

Questi sei concetti coprono catalogo, storage confinato e i due accessi model-facing in un Agent che non possiede un file reader generale.

Il settimo concetto, un middleware di preservazione, va aggiunto **solo** se la specifica Neuron mantiene la garanzia post-compaction. Prima di introdurlo, conviene verificare se trimming/summarization può proteggere direttamente il `ToolResultMessage`; sarebbe più vicino alla guida ufficiale e non richiederebbe un duplicato delle istruzioni nello stato Workflow.

Non introdurrei `ActiveSkill`, `ActiveSkills` o `LoadedSkill` nella prima versione. Se servirà uno stato durevole, il nome e la forma dovrebbero derivare dalla strategia scelta (`ProtectedSkillResult`, oppure soltanto una chiave interna del middleware), non diventare prematuramente un'entità pubblica del dominio.
