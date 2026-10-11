<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateProposalDraftTool;
use App\Mcp\Tools\CreateSubtaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\ListCompaniesTool;
use App\Mcp\Tools\ListContactsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ListUnbilledTimeTool;
use App\Mcp\Tools\LogTimeTool;
use App\Mcp\Tools\UpdateSubtaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

// The studio app for Claude (routes/ai.php): read projects, clients,
// contacts, tasks and unbilled time; create and update tasks, log time and
// save proposal drafts -- as the signed-in staff member, within their
// permissions. Nothing here sends, accepts, emails or deletes anything.
#[Name('Madhouse Studio')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
The studio's project-management app: clients (companies) and their contacts, projects, tasks, time tracking and proposals. You act as the signed-in staff member and see only what they can.
- Look things up first: list_projects / get_project, list_companies, list_contacts, list_tasks. Use the IDs they return.
- create_task (optionally with subtasks), create_subtask, update_task, update_subtask and log_time change the app straight away; confirm details with the user when unsure (which project, how many hours, which day).
- create_proposal_draft only saves a draft for the team to review and send in the app. Nothing here sends, accepts or emails proposals or invoices, or deletes anything.
- Every result has a url back into the app; share it.
TEXT)]
class StudioServer extends Server
{
    protected array $tools = [
        ListProjectsTool::class,
        GetProjectTool::class,
        ListCompaniesTool::class,
        ListContactsTool::class,
        ListTasksTool::class,
        ListUnbilledTimeTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        CreateSubtaskTool::class,
        UpdateSubtaskTool::class,
        LogTimeTool::class,
        CreateProposalDraftTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
