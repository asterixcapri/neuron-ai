# Agent Skills: confronto fra Ligea e Neuron AI

Data della ricerca: 2026-09-01

## Sintesi

Ligea conferma che il modello di base e valido: un indice compatto delle skill disponibili, istruzioni caricate tramite un normale tool e risorse lette solo quando servono. Non conviene pero copiare la sua implementazione letteralmente. Ligea lega tutto a una directory applicativa fissa, legge l'intero `SKILL.md` anche durante la discovery, non distingue una skill caricata da una skill attiva, perde le istruzioni quando il tool result esce dalla history e offre automaticamente l'esecuzione degli script.

La direzione corrente di Neuron e migliore nei punti strutturali: repository storage-neutral, root esplicite, composizione con ownership univoca, due soli tool di lettura, input enum, run key sensibili agli input, confinamento dei symlink e lifecycle in middleware. La scelta concordata di registrare esplicitamente **toolkit e middleware** e coerente con le extension point esistenti e consente di eliminare le modifiche ai nodi interni.

Restano alcune correzioni consigliate prima di considerare chiuso il design:

1. spostare il middleware fuori da `NeuronAI\Agent\Middleware`, dentro un modulo opzionale `NeuronAI\AgentSkills`, affinche il core Agent non dipenda dal tipo concreto `SkillLoadTool`;
2. chiarire il vocabolario `catalog entry` / `loaded skill` / `active skill`;
3. irrobustire la presentazione del catalogo contro descrizioni multilinea, cataloghi molto grandi e contenuto istruzionale nelle descrizioni;
4. rendere `skill_load` sicuro rispetto a errori e stato residuo;
5. aggiungere test espliciti per ordering dei middleware e `ParallelToolNode`.

## Fonti e metodo

La ricerca e read-only su Ligea. Sono stati letti codice applicativo, configurazione, README e skill installate nel checkout `/home/asterix/asterixcapri/ligea`, seguendo le istruzioni del suo [`AGENTS.md`](../../../../ligea/AGENTS.md). Ligea non contiene attualmente una directory `tests/`; `composer.json` dichiara soltanto l'autoload dev per una futura `App\Tests\`, senza PHPUnit o script di test ([`composer.json`, righe 52-60 e 74-90](../../../../ligea/composer.json#L52)).

Per Neuron sono stati usati la [spec corrente](../spec.md), i ticket, il codice del branch `feature/agent-skills` e i test. Il checkout aveva una refactor middleware non ancora committata durante la ricerca; le osservazioni sul lifecycle si riferiscono a quella versione corrente.

Misura riproducibile sul checkout Ligea: il registry trova 101 skill valide. La stringa prodotta da `describeCatalog()` e di 30.201 byte; i 101 `SKILL.md` totalizzano 792.526 byte. La misura e stata fatta istanziando `SkillRegistry` con `SkillFileParser` e `NullLogger`, quindi usa lo stesso percorso applicativo di discovery documentato dal codice ([`SkillRegistry.php`, righe 101-118](../../../../ligea/src/Service/Skill/SkillRegistry.php#L101)).

## Cosa significano “catalogo” e “guidelines”

### Catalogo

Il catalogo non e l'insieme delle istruzioni caricate. E l'**indice delle skill disponibili per quell'Agent**. Ogni voce contiene almeno:

- nome stabile, usato come identificatore del tool call;
- descrizione breve, usata dal modello per decidere se vale la pena caricarla.

In Neuron questo indice e `SkillRepositoryInterface::catalog()`, che restituisce `SkillMetadata[]`; le istruzioni e le risorse sono operazioni separate e lazy ([`SkillRepositoryInterface.php`, righe 7-21](../../../src/Tools/Toolkits/AgentSkills/SkillRepositoryInterface.php#L7)). Il `CompositeSkillRepository` unisce piu indici e conserva il repository proprietario di ogni skill, cosi istruzioni e risorse provengono sempre dalla stessa fonte ([`CompositeSkillRepository.php`, righe 27-77](../../../src/Tools/Toolkits/AgentSkills/CompositeSkillRepository.php#L27)).

Quindi, nel dominio:

| Termine | Significato | Dura quanto |
|---|---|---|
| available / catalog entry | La skill e selezionabile; sono noti nome e descrizione | Finche il repository e registrato |
| loaded skill | Le istruzioni complete sono state lette con successo in un tool call | E un evento/risultato puntuale |
| active skill | Quelle istruzioni devono continuare a influenzare la conversazione | Fino alla fine della conversazione |

Ligea implementa bene “available” e “loaded”, ma non “active”: `LoadSkillTool` restituisce istruzioni e metadati nel risultato ([`LoadSkillTool.php`, righe 36-65](../../../../ligea/src/Tool/Skill/LoadSkillTool.php#L36)), senza registrare alcuno stato di conversazione.

### Guidelines del toolkit

Le guidelines sono testo di coordinamento che Neuron aggiunge automaticamente al system prompt quando registra un toolkit. Il bootstrap raccoglie `ToolkitInterface::guidelines()`, aggiunge il nome del toolkit e i nomi dei tool e racchiude tutto in `<TOOLS-GUIDELINES>` ([`HandleTools.php`, righe 129-163](../../../src/Agent/HandleTools.php#L129)).

Dire “il catalogo e nelle proprie guidelines” significa quindi: `SkillToolkit` genera dalle proprie dipendenze la lista `nome: descrizione`, insieme alla regola “carica una skill rilevante prima di seguirne le istruzioni”; l'applicazione non deve copiare il catalogo nel prompt dell'Agent ([`SkillToolkit.php`, righe 18-42](../../../src/Tools/Toolkits/AgentSkills/SkillToolkit.php#L18)).

Ligea divide invece la responsabilita in due punti:

- il toolkit contiene regole generiche su come usare skill, risorse e script ([`SkillToolkit.php`, righe 17-34](../../../../ligea/src/Tool/Skill/SkillToolkit.php#L17));
- `AssistantAgent::instructions()` appende manualmente `describeCatalog()` ([`AssistantAgent.php`, righe 93-109](../../../../ligea/src/Agent/AssistantAgent.php#L93)).

Per un framework e piu portabile la scelta Neuron: toolkit, catalogo e tool restano un'unica capability installabile. Da Ligea conviene pero riprendere due frasi esplicite: “le voci del catalogo non sono tool” e “carica la skill prima di affidarti alle istruzioni dettagliate”.

## Confronto per area

### 1. Discovery, parsing e catalogo

**Ligea**

- cerca solo directory figlie dirette con `SKILL.md`, ordina con `ksort` e isola gli errori per skill tramite warning ([`SkillRegistry.php`, righe 124-170](../../../../ligea/src/Service/Skill/SkillRegistry.php#L124));
- usa il vero parser YAML Symfony e verifica nome, directory, descrizione e metadati ([`SkillFileParser.php`, righe 12-37](../../../../ligea/src/Service/Skill/SkillFileParser.php#L12), [`SkillRegistry.php`, righe 173-223](../../../../ligea/src/Service/Skill/SkillRegistry.php#L173));
- e pero hard-coded su `$projectDir/.agents/skills`, quindi non puo scegliere root per Agent ne combinare filesystem, database e remoto ([`SkillRegistry.php`, righe 130-132](../../../../ligea/src/Service/Skill/SkillRegistry.php#L130));
- la discovery non e realmente metadata-only: `SkillFileParser::parse()` usa `file_get_contents()` sull'intero file prima di estrarre il frontmatter ([`SkillFileParser.php`, righe 18-37](../../../../ligea/src/Service/Skill/SkillFileParser.php#L18)). Nel checkout legge quindi circa 793 KB per produrre il catalogo, prima di qualsiasi attivazione.

**Neuron**

- riceve una root esplicita, scopre figli diretti e legge il frontmatter a streaming fino al delimitatore di chiusura ([`FileSystemSkillRepository.php`, righe 47-99 e 215-240](../../../src/Tools/Toolkits/AgentSkills/FileSystemSkillRepository.php#L47));
- carica il body solo in `load()` ([`FileSystemSkillRepository.php`, righe 103-110](../../../src/Tools/Toolkits/AgentSkills/FileSystemSkillRepository.php#L103));
- espone diagnostica osservabile e implementazioni componibili senza legare il toolkit al filesystem ([`SkillRepositoryInterface.php`, righe 7-21](../../../src/Tools/Toolkits/AgentSkills/SkillRepositoryInterface.php#L7)).

**Valutazione:** riusare il flusso Ligea e il parser YAML, ma mantenere l'architettura repository di Neuron. Non importare il `SkillRegistry` monolitico.

### 2. Progressive disclosure

Ligea ha tre livelli concettualmente corretti:

1. catalogo nome/descrizione nel prompt;
2. body completo tramite `load_skill`;
3. file aggiuntivi tramite `read_skill_resource` ([`AssistantAgent.php`, righe 103-109](../../../../ligea/src/Agent/AssistantAgent.php#L103), [`ReadSkillResourceTool.php`, righe 23-48](../../../../ligea/src/Tool/Skill/ReadSkillResourceTool.php#L23)).

Questa e l'idea piu importante da riusare. Neuron la generalizza correttamente: `skill_load_resource` accetta qualsiasi path logico relativo, non soltanto `references/` o `assets/` ([`SkillLoadResourceTool.php`, righe 30-59](../../../src/Tools/Toolkits/AgentSkills/SkillLoadResourceTool.php#L30)).

Due miglioramenti emersi dai contenuti reali di Ligea:

- molte skill puntano a `../../CONNECTORS.md`, cioe fuori dal proprio package; per esempio [`accessibility-review/SKILL.md`, riga 9](../../../../ligea/.agents/skills/accessibility-review/SKILL.md#L9). Il confinement corretto deve continuare a rifiutarlo: vanno adattate le skill o va fornita una capability separata per documentazione condivisa, non aperta una traversata;
- alcune descrizioni YAML sono multilinea e molto lunghe, per esempio [`brand-voice-enforcement/SKILL.md`, righe 1-13](../../../../ligea/.agents/skills/brand-voice-enforcement/SKILL.md#L1). Interpolate direttamente in `- nome: descrizione`, rompono il formato “una voce per riga” e fanno crescere molto il prompt.

### 3. Sicurezza dei path e dei contenuti

Ligea usa `realpath()` e un controllo di prefisso per impedire che una risorsa esca dalla root della skill ([`SkillRegistry.php`, righe 236-263](../../../../ligea/src/Service/Skill/SkillRegistry.php#L236)). E una buona base e l'esecuzione usa un argv array, non una command string ([`SkillRegistry.php`, righe 64-98](../../../../ligea/src/Service/Skill/SkillRegistry.php#L64)).

Non e sufficiente come implementazione framework:

- `skillRoot` e `SKILL.md` non vengono canonicalizzati durante la discovery. Una directory-symlink o un manifest-symlink esterno puo quindi far leggere metadati e istruzioni fuori da `.agents/skills` ([`SkillRegistry.php`, righe 147-159](../../../../ligea/src/Service/Skill/SkillRegistry.php#L147));
- `readSkillResource()` restituisce byte arbitrari senza controllo UTF-8/binario o limite di dimensione ([`SkillRegistry.php`, righe 44-59](../../../../ligea/src/Service/Skill/SkillRegistry.php#L44));
- gli errori di `ReadSkillResourceTool` non vengono trasformati localmente in risultati machine-readable: l'eccezione del registry esce direttamente dal tool ([`ReadSkillResourceTool.php`, righe 41-48](../../../../ligea/src/Tool/Skill/ReadSkillResourceTool.php#L41)).

Neuron e gia piu forte: canonicalizza root, directory e manifest, controlla il boundary con separatore, rifiuta assoluti POSIX/Windows/UNC e `..`, ricontrolla i symlink al caricamento e respinge binario/non UTF-8 ([`FileSystemSkillRepository.php`, righe 65-99, 113-144 e 163-179](../../../src/Tools/Toolkits/AgentSkills/FileSystemSkillRepository.php#L65)). I test coprono directory e manifest symlink esterni, inclusa la sostituzione dopo discovery ([`FileSystemSkillRepositoryTest.php`, righe 204-278](../../../tests/Tools/AgentSkills/FileSystemSkillRepositoryTest.php#L204)).

Miglioramento ulteriore per Neuron: aggiungere in futuro un limite configurabile di byte per manifest/resource. Entrambe le implementazioni fanno ancora `file_get_contents()` senza limite; il confinement impedisce data exfiltration, ma non memory/context exhaustion.

### 4. Activation e lifecycle

Ligea usa correttamente provider-native tool calling, ma “load” significa soltanto “leggi ora”. Non c'e deduplica, stato active, reiniezione o isolamento esplicito per conversazione. Il catalogo stesso dice solo di chiamare `load_skill` ([`SkillRegistry.php`, righe 101-118](../../../../ligea/src/Service/Skill/SkillRegistry.php#L101)).

Questo limite e concreto in Ligea perche le chat usano history persistente ([`ChatRuntimeResolver.php`, righe 18-39](../../../../ligea/src/Chat/ChatRuntimeResolver.php#L18)) e il progetto contiene una summarization che sostituisce i messaggi vecchi con un riassunto ([`ConversationSummarizationFactory.php`, righe 18-36](../../../../ligea/src/Chat/ConversationSummarizationFactory.php#L18)). Quando il tool result originale non e piu nella history, le istruzioni complete non sono recuperabili in modo affidabile dal riassunto.

Il middleware Neuron corrente colma il vuoto:

- dopo `ToolNode`, riconosce un `SkillLoadTool` riuscito e registra la skill nello `WorkflowState` ([`SkillLifecycle.php`, righe 66-111](../../../src/Agent/Middleware/SkillLifecycle.php#L66));
- prima di `InferenceNode`, reinietta le istruzioni soltanto se il tool result equivalente non e piu nella conversazione ([`SkillLifecycle.php`, righe 33-63 e 125-142](../../../src/Agent/Middleware/SkillLifecycle.php#L33));
- rimuove prima il proprio blocco delimitato, evitando accumulo nell'`AIInferenceEvent` riusato ([`SkillLifecycle.php`, righe 29-45](../../../src/Agent/Middleware/SkillLifecycle.php#L29)).

La registrazione esplicita concordata e coerente:

```php
$skills = new SkillLifecycle();

$agent->addTool($skillToolkit);
$agent->addMiddleware(ToolNode::class, $skills);
$agent->addMiddleware(InferenceNode::class, $skills);
```

Il matching e subclass-aware, quindi copre `ParallelToolNode` e le tre modalita di inferenza senza modificare i nodi ([`HandleMiddleware.php`, righe 99-123](../../../src/Workflow/HandleMiddleware.php#L99)). I test Agent-facing verificano deduplica, trimming, summarization, isolamento, streaming e structured output ([`SkillToolkitTest.php`, righe 125-290](../../../tests/Tools/AgentSkills/SkillToolkitTest.php#L125)).

Il punto fragile e l'ordine: i `before()` vengono eseguiti nell'ordine di registrazione ([`WorkflowExecutor.php`, righe 375-387](../../../src/Workflow/Executor/WorkflowExecutor.php#L375)). Se `SkillLifecycle` gira prima di `Summarization`, puo vedere ancora il vecchio tool result, decidere di non reiniettare e poi lasciare che la summarization lo elimini. La spec corrente lo dice; serve anche documentazione d'uso evidente e un test che dimostri l'ordine richiesto.

### 5. Middleware e confini di modulo

La logica e adatta a un middleware, ma la collocazione corrente e migliorabile. `NeuronAI\Agent\Middleware\SkillLifecycle` importa `ActiveSkill` e `SkillLoadTool` dal toolkit ([`SkillLifecycle.php`, righe 5-19](../../../src/Agent/Middleware/SkillLifecycle.php#L5)). Cosi il modulo core `Agent` conosce una feature opzionale concreta, anche se i nodi non vengono toccati.

Una struttura piu chiara sarebbe:

```text
src/AgentSkills/
  SkillMetadata.php
  SkillRepositoryInterface.php
  Repository/FileSystemSkillRepository.php
  Repository/CompositeSkillRepository.php
  Tools/SkillToolkit.php
  Tools/SkillLoadTool.php
  Tools/SkillLoadResourceTool.php
  Middleware/SkillLifecycle.php
```

In questo modo **Agent Skills dipende da Agent, Workflow e Tools**, mentre Agent e Tools non dipendono da Agent Skills. E un modulo opzionale verticale, non un internal dell'Agent ne un dettaglio interno dei toolkits.

Non aggiungerei middleware a `ToolkitInterface`: oggi il contratto riguarda soltanto guidelines e tool ([`ToolkitInterface.php`, righe 9-31](../../../src/Tools/Toolkits/ToolkitInterface.php#L9)); estenderlo romperebbe implementazioni esistenti e introdurrebbe una dipendenza Tools -> Workflow. La registrazione separata scelta dall'utente mantiene i confini piu puliti.

### 6. Testabilita

Ligea ha servizi piccoli e dependency injection Symfony, ma non ha test dell'implementazione skill. Mancano prove per parsing invalido, traversal, symlink, binario, errori, tool run limits e perdita dopo trimming/summarization. Il suo `SkillRegistry` accorpa discovery, parsing, catalog rendering, resource resolution e script command resolution, rendendo i test piu larghi del necessario ([`SkillRegistry.php`, righe 9-284](../../../../ligea/src/Service/Skill/SkillRegistry.php#L9)).

Neuron separa le seam e testa comportamento pubblico:

- filesystem e diagnostica ([`FileSystemSkillRepositoryTest.php`, righe 49-147](../../../tests/Tools/AgentSkills/FileSystemSkillRepositoryTest.php#L49));
- composizione e ownership atomica ([`CompositeSkillRepositoryTest.php`, righe 51-139](../../../tests/Tools/AgentSkills/CompositeSkillRepositoryTest.php#L51));
- catalogo/tool loop/lifecycle attraverso Agent e fake provider ([`SkillToolkitTest.php`, righe 73-113 e 125-290](../../../tests/Tools/AgentSkills/SkillToolkitTest.php#L73)).

Test aggiuntivi consigliati:

1. lifecycle con `parallelToolCalls(true)` e almeno due tool call nello stesso turno;
2. ordering: summarization registrata prima funziona, ordine inverso viene documentato o diagnosticato;
3. `skill_load` noto in catalogo ma fallito durante il load produce un risultato testuale e non lascia activation stale;
4. descrizione multilinea viene normalizzata in una singola voce di catalogo;
5. catalog size/resource size policy, se viene introdotta;
6. collisione della chiave di stato, usando una chiave namespaced anziche il generico `__active_skills` ([`SkillLifecycle.php`, righe 29-32](../../../src/Agent/Middleware/SkillLifecycle.php#L29)).

## Vocabolario e naming

### Ligea

- `App\Service\Skill` contiene parser, metadata e un `SkillRegistry` filesystem-specific;
- `App\Tool\Skill` contiene toolkit e tre tool;
- `registry` suggerisce un indice generale, ma la classe e in realta insieme repository filesystem, catalog builder, resource resolver e script resolver;
- `load_skill` indica correttamente un'operazione, ma il codice e la documentazione usano talvolta “activate” senza implementare uno stato active ([`README.md`, righe 131-148](../../../../ligea/README.md#L131));
- non esistono tipi o collezioni `LoadedSkill` / `ActiveSkill`.

### Neuron corrente

- `catalog()` e un buon nome per l'indice disponibile;
- `SkillRepositoryInterface::load()` e troppo generico: `loadInstructions()` esprimerebbe il confine storage-neutral con maggiore precisione;
- `SkillMetadata` e corretto come contenuto del manifest, ma `SkillCatalogEntry` sarebbe piu preciso se il valore pubblico fosse intenzionalmente limitato a nome/descrizione;
- `ActiveSkill` viene costruita dentro `SkillLoadTool` prima che il middleware la registri come attiva ([`SkillLoadTool.php`, righe 44-58](../../../src/Tools/Toolkits/AgentSkills/SkillLoadTool.php#L44)). In quel momento e **loaded**, non ancora **active**.

Naming consigliato:

| Concetto | Nome consigliato |
|---|---|
| voce disponibile | `SkillMetadata` oppure `SkillCatalogEntry` |
| risultato di lettura istruzioni | `LoadedSkill` |
| record persistente di conversazione | `SkillActivation` oppure `ActiveSkill` |
| storage | `SkillRepositoryInterface::loadInstructions()` |
| indice aggregato | `CompositeSkillRepository::catalog()` |

La separazione piu rigorosa sarebbe: il tool produce `LoadedSkill`; il middleware, dopo il successo, crea `SkillActivation`. Se si preferisce meno codice, rinominare l'attuale `ActiveSkill` in `LoadedSkill` e chiamare `activeSkills()` la sola mappa nello state e comunque piu fedele al comportamento.

## Decisioni: riusare, migliorare, non importare

### Riusare da Ligea

- Catalogo nome/descrizione nel system prompt, con istruzioni complete lazy.
- Normale tool calling per caricare skill e risorse.
- Parser YAML reale e validazione nome-directory.
- Discovery deterministica dei soli figli diretti.
- Skill invalide isolate senza bloccare le valide.
- Guidelines esplicite: una voce di catalogo non e un tool; caricare prima di seguire.
- Risultati di tool comprensibili dal modello.

### Migliorare in Neuron

- Spostare tutta la feature in `NeuronAI\AgentSkills`, incluso il middleware.
- Distinguere nel naming available, loaded e active.
- Rinominare `load()` del repository in `loadInstructions()` se la compatibilita lo consente.
- Normalizzare whitespace delle descrizioni solo nella resa del catalogo.
- Delimitare il catalogo come dati di selezione, non come istruzioni operative; le descrizioni provengono da package installati e possono contenere testo imperativo.
- Documentare e testare l'ordine dopo `Summarization`.
- Azzerare lo stato transiente del `SkillLoadTool` all'inizio di ogni invocazione e trasformare i fallimenti di load in risultati model-readable. Oggi `activeSkill` resta una property del tool e `load()` non e racchiuso in un `try/catch` ([`SkillLoadTool.php`, righe 19 e 44-63](../../../src/Tools/Toolkits/AgentSkills/SkillLoadTool.php#L19)).
- Considerare limiti di dimensione per catalogo e risorse, basandosi sull'evidenza dei 30 KB del catalogo Ligea.
- Aggiungere il test reale del ramo parallelo.

### Non importare da Ligea

- Root implicita `.agents/skills` condivisa da tutta l'applicazione.
- `SkillRegistry` monolitico e filesystem-specific.
- Parsing eager dell'intero body durante la discovery.
- Catalogo appeso manualmente dall'Agent oltre alla capability del toolkit.
- Assenza di stato active e affidamento esclusivo alla chat history.
- `RunSkillScriptTool`: viene esposto automaticamente dal toolkit ([`SkillToolkit.php`, righe 28-34](../../../../ligea/src/Tool/Skill/SkillToolkit.php#L28)) ed esegue PHP, Bash o Python con `proc_open`, senza timeout o limite di output ([`RunSkillScriptTool.php`, righe 55-97](../../../../ligea/src/Tool/Skill/RunSkillScriptTool.php#L55)). Installare istruzioni non deve equivalere a concedere code execution.
- `allowed-tools` come promessa di sicurezza: Ligea lo parsea e lo restituisce al modello, ma non lo usa per autorizzare i tool ([`SkillRegistry.php`, righe 208-223](../../../../ligea/src/Service/Skill/SkillRegistry.php#L208), [`LoadSkillTool.php`, righe 54-64](../../../../ligea/src/Tool/Skill/LoadSkillTool.php#L54)).
- Lettura binaria illimitata e accesso a symlink non canonicalizzati nella discovery.

## Raccomandazione finale

Confermare il design **toolkit + middleware registrati esplicitamente**, mantenendo invariati `AgentState`, `ToolNode`, `InferenceNode`, `ChatNode`, `StreamingNode` e `StructuredOutputNode`.

Prima del merge, farei tre interventi chirurgici:

1. ricollocare la feature sotto un namespace top-level `NeuronAI\AgentSkills` per mantenere il core Agent ignaro dell'estensione;
2. correggere naming e robustezza del record loaded/active e del fallimento di `skill_load`;
3. migliorare guidelines/catalog rendering e completare i test di ordering e parallel tools.

Ligea resta un buon proof of concept del flusso di progressive disclosure. Neuron non dovrebbe copiarne il runtime, ma generalizzarne l'intuizione con repository, sicurezza e lifecycle espliciti.
