# php-ecs-to-google-cloud

Auth Google Cloud from AWS ECS

# PHP on AWS ECS to Google Cloud Authentication

This repository demonstrates how to authenticate to Google Cloud services from a PHP application running on AWS ECS,
using [Workload Identity Federation](https://cloud.google.com/iam/docs/workload-identity-federation). This method avoids
the need for long-lived service account keys, enhancing security.

The detailed article (in Japanese) can be found here: [ariticle/article.md](./ariticle/article.md).

The sample code and Docker images used in the article are stored in this repository.

## How it Works

The core of this solution is a custom credential provider for the Google Auth Library for PHP. Since the default library
doesn't support the specific way ECS provides credentials to containers, we use two custom classes:

* `ExternalAccountCredentialsByAws.php`: Manages the overall authentication flow, similar to the official
  `ExternalAccountCredentials`, but allows for a custom subject token fetcher.
* `AwsSdkSource.php`: Fetches the AWS credentials using the official AWS SDK for PHP, which correctly handles various
  credential sources including the ECS task role metadata endpoint. It then creates a signed `GetCallerIdentity` request
  to be used as the subject token for Google's STS.

## Setup

### Prerequisites

* Docker
* Google Cloud SDK (`gcloud` CLI)
* AWS CLI
* Terraform (optional, for provisioning Google Cloud resources)

### 1. Google Cloud Configuration

You need to set up a Workload Identity Pool and a Provider to trust identities from your AWS account.

An example of how to create these resources using Terraform is provided in [ariticle/article.md](./ariticle/article.md).

You also need a Service Account that your AWS role will impersonate. Grant this service account the necessary roles (
e.g., Storage Object Viewer) for the resources you want to access. Then, grant the AWS role the "Workload Identity User"
role (`roles/iam.workloadIdentityUser`) on the service account.

#### Required IAM Roles

For the sample code in this repository to work, the impersonated Service Account needs the following IAM roles:

* `roles/storage.viewer`: Allows the application to read metadata from Google Cloud Storage buckets.
* `roles/firebaseauth.viewer`: Allows the application to list users from Firebase Authentication.

### 2. Create Credential Configuration File

Generate a credential configuration file using the `gcloud` CLI. This file tells the Google Cloud client library how to
federate credentials from AWS. It should be named `auth.json` and placed in the `php/` directory.

```sh
gcloud iam workload-identity-pools create-cred-config \
    "projects/PROJECT_NUMBER/locations/global/workloadIdentityPools/POOL_ID/providers/PROVIDER_ID" \
    --service-account="SERVICE_ACCOUNT_EMAIL" \
    --output-file="php/auth.json" \
    --aws
```

**Replace the following:**

* `PROJECT_NUMBER`: Your Google Cloud project number.
* `POOL_ID`: Your Workload Identity Pool ID.
* `PROVIDER_ID`: Your Workload Identity Pool Provider ID.
* `SERVICE_ACCOUNT_EMAIL`: The email of the service account to impersonate.

This command will create an `auth.json` file. This file does not contain secrets and can be safely included in your
application's container image. See
the [official documentation](https://cloud.google.com/iam/docs/workload-identity-federation-with-other-clouds?hl=en#create-cred-config)
for more details.

### 3. AWS Configuration

The authentication from AWS to Google Cloud is based on a trusted IAM Role. You need to configure an AWS IAM Role whose
identity will be trusted by your Google Cloud Workload Identity Pool. The ARN of this role (or a specific attribute of
it) is what you use in the attribute mapping of your Workload Identity Provider on Google Cloud.

**On AWS ECS (Production):**
The primary use case is to run this application on ECS. Create an IAM Role for your ECS tasks and attach it to your task
definition. The application, using the AWS SDK, will automatically discover and use these credentials from the ECS task
metadata service.

**For Local Development:**
The application uses the AWS SDK's default credential provider chain. This means for local testing, you can use any
standard AWS authentication method, such as a named profile in your `~/.aws/credentials` file, configured via the
`AWS_PROFILE` environment variable.

The key is to understand which identity is presented to Google Cloud:

* **On ECS**, the identity is the **Task Role**.
* **Locally**, the identity is your **IAM User** (or the role associated with your profile).

Therefore, for local testing to work, your Google Cloud Workload Identity Provider must be configured to trust **both**
identities. You need to add a condition that accepts your local IAM User's ARN *in addition to* the ECS Task Role's ARN.

For example, you could adjust your provider's attribute mapping on Google Cloud to accept multiple specific roles or
users.

### 4. Running the Application with Docker

A pre-built Docker image is available on GitHub Container Registry (ghcr.io).

**1. Pull the Docker image:**

```sh
docker pull ghcr.io/k-kojima-yumemi/php-ecs-to-google-cloud:latest
```

**2. Run the container:**

To run the container, you need to provide the `auth.json` file created in step 2. The main script (`main.php`) also
reads the GCS bucket name from the `GCS_BUCKET` environment variable.

```sh
docker run --rm -it \
  -v $(pwd)/php/auth.json:/app/auth.json \
  -e GCS_BUCKET="your-gcs-bucket-name" \
  ghcr.io/k-kojima-yumemi/php-ecs-to-google-cloud:latest
```

* The `-v` flag mounts your local `auth.json` into the container at `/app/auth.json`.
* The `-e` flag sets the `GCS_BUCKET` environment variable.

**Running on AWS ECS:**
When running this container as an ECS task, configure your task definition to use the
`ghcr.io/k-kojima-yumemi/php-ecs-to-google-cloud:latest` image. The application will automatically use the credentials
provided by the ECS task role.

**Running Locally (with AWS profile):**
To test locally using your AWS profile, you also need to mount your AWS credentials.

```sh
docker run --rm -it \
  -v $(pwd)/php/auth.json:/app/auth.json \
  -v ~/.aws:/root/.aws \
  -e GCS_BUCKET="your-gcs-bucket-name" \
  -e AWS_PROFILE="your-aws-profile-name" \
  ghcr.io/k-kojima-yumemi/php-ecs-to-google-cloud:latest
```
