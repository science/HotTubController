# GitHub Operations Guide

This file provides guidance for working with GitHub CLI and project management operations.

## Project Board Management

### Overview
GitHub Project Boards (Projects V2) use GraphQL API under the hood. The `gh project` commands often have syntax issues or incomplete functionality. The most reliable approach is to use the GraphQL API directly.

### Common Task: Update Issue Status in Project Board

#### Step 1: Find Project and Issue Information
```bash
# Get project details
gh project view [PROJECT_NUMBER] --owner [USERNAME]

# Check issue's current project status
gh issue view [ISSUE_NUMBER] --json projectItems
```

#### Step 2: Get Status Field Options
```bash
# Get all status options for the project
gh api graphql -f query='
{
  user(login: "[USERNAME]") {
    projectV2(number: [PROJECT_NUMBER]) {
      field(name: "Status") {
        ... on ProjectV2SingleSelectField {
          options {
            id
            name
          }
        }
      }
    }
  }
}'
```

Example output:
```json
{
  "data": {
    "user": {
      "projectV2": {
        "field": {
          "options": [
            {"id": "f75ad846", "name": "Needs Refinement"},
            {"id": "315f600e", "name": "Ready to Implement"}, 
            {"id": "47fc9ee4", "name": "In Progress"},
            {"id": "86c5b991", "name": "Ready for UAT"},
            {"id": "98236657", "name": "Done"}
          ]
        }
      }
    }
  }
}
```

#### Step 3: Find Project Item ID
```bash
# Get all project items to find the specific issue
gh api graphql -f query='
{
  user(login: "[USERNAME]") {
    projectV2(number: [PROJECT_NUMBER]) {
      items(first: 50) {
        nodes {
          id
          content {
            ... on Issue {
              number
              title
            }
          }
        }
      }
    }
  }
}'
```

Look for your issue number and note the corresponding `id` value.

#### Step 4: Update Status
```bash
# Update the issue status using GraphQL mutation
gh api graphql -f query='
mutation {
  updateProjectV2ItemFieldValue(
    input: {
      projectId: "[PROJECT_ID]"
      itemId: "[PROJECT_ITEM_ID]"
      fieldId: "[STATUS_FIELD_ID]"
      value: {
        singleSelectOptionId: "[STATUS_OPTION_ID]"
      }
    }
  ) {
    projectV2Item {
      id
    }
  }
}'
```

### Real Working Example

Here's the actual command sequence that works:

```bash
# 1. Check current status
gh issue view 5 --json projectItems

# 2. Get status options 
gh api graphql -f query='
{
  user(login: "science") {
    projectV2(number: 1) {
      field(name: "Status") {
        ... on ProjectV2SingleSelectField {
          options {
            id
            name
          }
        }
      }
    }
  }
}'

# 3. Find project item ID
gh api graphql -f query='
{
  user(login: "science") {
    projectV2(number: 1) {
      items(first: 50) {
        nodes {
          id
          content {
            ... on Issue {
              number
              title
            }
          }
        }
      }
    }
  }
}'

# 4. Update to "Done" status
gh api graphql -f query='
mutation {
  updateProjectV2ItemFieldValue(
    input: {
      projectId: "PVT_kwHOAAEJbM4BC0xt"
      itemId: "PVTI_lAHOAAEJbM4BC0xtzgenhz0"
      fieldId: "PVTSSF_lAHOAAEJbM4BC0xtzg07Few" 
      value: {
        singleSelectOptionId: "98236657"
      }
    }
  ) {
    projectV2Item {
      id
    }
  }
}'
```

## Common Issues Management

### Close an Issue
```bash
# Close with comment and reason
gh issue close [ISSUE_NUMBER] --comment "[CLOSING_MESSAGE]" --reason completed

# Close as not planned
gh issue close [ISSUE_NUMBER] --reason "not planned"
```

### Add Comments to Issues
```bash
# Add a comment
gh issue comment [ISSUE_NUMBER] --body "[COMMENT_TEXT]"
```

### Update Issue Labels
```bash
# Add labels
gh issue edit [ISSUE_NUMBER] --add-label "bug,priority:high"

# Remove labels  
gh issue edit [ISSUE_NUMBER] --remove-label "status:in-progress"
```

## Project Field IDs and Common Values

### Finding Field IDs
Field IDs are stable but project-specific. Use this command to list all fields:

```bash
gh project field-list [PROJECT_NUMBER] --owner [USERNAME]
```

### Common Project Operations

#### Add Issue to Project
```bash
gh issue edit [ISSUE_NUMBER] --add-project "[USERNAME]/[PROJECT_NUMBER]"
```

#### Remove Issue from Project  
```bash
gh issue edit [ISSUE_NUMBER] --remove-project "[USERNAME]/[PROJECT_NUMBER]"
```

## Troubleshooting

### Problem: `gh project item-edit` fails
**Solution**: Use GraphQL API directly as shown above.

### Problem: "owner is required when not running interactively"
**Solution**: Always specify `--owner [USERNAME]` parameter.

### Problem: Can't find project item ID
**Solution**: Use the GraphQL query in Step 3 above to list all items.

### Problem: Invalid field or option IDs
**Solution**: Field IDs and option IDs are project-specific. Always query them first.

## Best Practices

1. **Always verify current state** before making changes
2. **Use GraphQL API** for project board operations
3. **Query IDs first** - don't assume they're the same across projects  
4. **Test commands** with read-only operations first
5. **Check results** after mutations to confirm success

## Quick Reference Commands

```bash
# View issue with project info
gh issue view [NUM] --json projectItems

# List all projects  
gh project list --owner [USERNAME]

# View project details
gh project view [NUM] --owner [USERNAME]

# Close issue
gh issue close [NUM] --reason completed

# Add comment
gh issue comment [NUM] --body "text"
```